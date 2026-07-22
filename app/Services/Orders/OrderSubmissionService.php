<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Models\Ordine;
use App\Models\OrdineItem;
use App\Models\Product;
use App\Models\User;
use App\Services\PrezziService;
use App\Services\Odoo\OdooQuoteRequestService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

final class OrderSubmissionService
{
    public function __construct(
        private readonly OrderQuotePdfService $pdfService,
        private readonly IgroupOrderMailService $igroupMailService,
        private readonly OdooQuoteRequestService $odooQuoteRequestService,
    ) {
    }

    public function submit(User $user, array $cart, string $confirmationNumber): Ordine
    {
        if ($cart === []) {
            throw ValidationException::withMessages([
                'cart' => 'Il carrello è vuoto.',
            ]);
        }

        $confirmationNumber = $this->normalizeConfirmationNumber($confirmationNumber);
        $this->validateCartPrices($user, $cart);
        $this->igroupMailService->ensureConfigured();

        $ordine = DB::transaction(function () use ($user, $cart, $confirmationNumber): Ordine {
            return $this->persistOrder($user, $cart, $confirmationNumber);
        });

        $ordine->loadMissing(['user.cliente', 'centroCosto', 'items.prodotto']);

        $pdf = $this->pdfService->generate($ordine);
        $this->igroupMailService->send($ordine, $pdf['path'], $pdf['filename']);
        $this->odooQuoteRequestService->sync($ordine, $pdf['content'], $pdf['filename']);

        $ordine->forceFill([
            'stato' => 'inviato',
        ])->save();

        Log::info('Order submission workflow completed', [
            'ordine_id' => $ordine->id,
            'confirmation_number' => $confirmationNumber,
        ]);

        return $ordine->fresh(['user.cliente', 'centroCosto', 'items.prodotto']) ?? $ordine;
    }

    private function persistOrder(User $user, array $cart, string $confirmationNumber): Ordine
    {
        $existingOrder = Ordine::query()
            ->where('user_id', $user->id)
            ->where('riferimento_cliente', $confirmationNumber)
            ->latest('id')
            ->lockForUpdate()
            ->first();

        if ($existingOrder !== null) {
            return $this->reuseExistingOrder($existingOrder, $cart, $user, $confirmationNumber);
        }

        $ordine = Ordine::create([
            'user_id' => $user->id,
            'centro_costo_id' => $this->resolveCentroCostoId($user),
            'stato' => 'bozza',
            'riferimento_cliente' => $confirmationNumber,
            'note' => null,
            'extra_budget' => false,
            'totale_lordo' => 0,
            'totale_netto' => 0,
            'iva_totale' => 0,
            'pdf_path' => null,
            'odoo_lead_id' => null,
            'igroup_sent_at' => null,
            'odoo_synced_at' => null,
        ]);

        $this->replaceOrderItems($ordine, $cart, $user);

        return $ordine;
    }

    private function reuseExistingOrder(Ordine $ordine, array $cart, User $user, string $confirmationNumber): Ordine
    {
        if ($ordine->stato !== 'bozza') {
            throw ValidationException::withMessages([
                'confirmation_number' => 'Questo numero conferma ordine e\' gia\' stato utilizzato.',
            ]);
        }

        if ($this->hasOutboundActivity($ordine)) {
            Log::warning('Retrying partially submitted order without rewriting items', [
                'ordine_id' => $ordine->id,
                'confirmation_number' => $confirmationNumber,
                'igroup_sent_at' => $ordine->igroup_sent_at?->format('Y-m-d H:i:s'),
                'odoo_lead_id' => $ordine->odoo_lead_id,
            ]);

            if (!$ordine->items()->exists()) {
                throw ValidationException::withMessages([
                    'confirmation_number' => 'Esiste gia\' un ordine incompleto con questo numero conferma ma senza righe valide. Contatta l\'assistenza.',
                ]);
            }

            return $ordine;
        }

        $ordine->forceFill([
            'centro_costo_id' => $this->resolveCentroCostoId($user),
            'riferimento_cliente' => $confirmationNumber,
            'pdf_path' => null,
        ])->save();

        $this->replaceOrderItems($ordine, $cart, $user);

        return $ordine;
    }

    private function replaceOrderItems(Ordine $ordine, array $cart, User $user): void
    {
        $products = $this->loadProductsForCart($cart);
        $rows = $this->buildOrderItemRows($ordine, $cart, $products, $user);

        $ordine->items()->delete();

        if ($rows !== []) {
            OrdineItem::query()->insert($rows);
        }

        $ordine->unsetRelation('items');
        $ordine->load('items');
        $ordine->ricalcolaTotali();
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $cart
     * @return Collection<int, Product>
     */
    private function loadProductsForCart(array $cart): Collection
    {
        $productIds = collect($cart)
            ->pluck('prodotto_id')
            ->filter(static fn (mixed $value): bool => is_numeric($value) && (int) $value > 0)
            ->map(static fn (mixed $value): int => (int) $value)
            ->unique()
            ->values();

        if ($productIds->isEmpty()) {
            throw ValidationException::withMessages([
                'cart' => 'Il carrello non contiene prodotti validi.',
            ]);
        }

        $products = Product::query()
            ->with('packagings')
            ->whereIn('id', $productIds)
            ->get(['id', 'unita_misura', 'disponibile'])
            ->keyBy('id');

        $missingProductIds = $productIds
            ->reject(static fn (int $productId): bool => $products->has($productId))
            ->values()
            ->all();

        if ($missingProductIds !== []) {
            throw ValidationException::withMessages([
                'cart' => 'Alcuni prodotti del carrello non sono piu\' disponibili.',
            ]);
        }

        return $products;
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $cart
     * @param  Collection<int, Product>  $products
     * @return array<int, array<string, int|float|string|null>>
     */
    private function buildOrderItemRows(Ordine $ordine, array $cart, Collection $products, User $user): array
    {
        $now = now();
        $rows = [];

        foreach ($cart as $item) {
            $productId = $this->normalizeRequiredProductId($item['prodotto_id'] ?? null);
            $product = $products->get($productId);

            if (!$product instanceof Product) {
                throw ValidationException::withMessages([
                    'cart' => 'Il carrello contiene un prodotto non valido.',
                ]);
            }

            $quantity = max(1, (int) ($item['quantita'] ?? 1));
            $unit = $this->normalizeUnit($item['unita'] ?? null, $product->unita_misura);
            $pricing = $this->validPricingForProduct($product, $user);
            $grossUnitPrice = $this->grossUnitPriceForUnit($product, $unit, $pricing);
            $discount = $this->normalizeDecimal($pricing['sconto_percentuale'] ?? 0);
            $ivaPercentage = $this->normalizeDecimal($pricing['iva_percentuale'] ?? 22);

            $grossDiscountedUnitPrice = round($grossUnitPrice * (1 - ($discount / 100)), 4);
            $grossLineTotal = round($grossDiscountedUnitPrice * $quantity, 2);
            $netLineTotal = round($grossLineTotal / (1 + ($ivaPercentage / 100)), 2);
            $taxLineTotal = round($grossLineTotal - $netLineTotal, 2);

            $rows[] = [
                'ordine_id' => $ordine->id,
                'prodotto_id' => $productId,
                'unita' => $unit,
                'quantita' => $quantity,
                'prezzo_unitario_lordo' => $grossUnitPrice,
                'sconto_percentuale' => $discount,
                'iva_percentuale' => $ivaPercentage,
                'totale_riga_netto' => $netLineTotal,
                'totale_riga_iva' => $taxLineTotal,
                'totale_riga_lordo' => $grossLineTotal,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        return $rows;
    }

    private function validateCartPrices(User $user, array $cart): void
    {
        $products = $this->loadProductsForCart($cart);

        foreach ($cart as $item) {
            $productId = $this->normalizeRequiredProductId($item['prodotto_id'] ?? null);
            $product = $products->get($productId);

            if (!$product instanceof Product) {
                throw ValidationException::withMessages([
                    'cart' => 'Il carrello contiene un prodotto non valido.',
                ]);
            }

            $unit = $this->normalizeUnit($item['unita'] ?? null, $product->unita_misura);
            $pricing = $this->validPricingForProduct($product, $user);
            $this->grossUnitPriceForUnit($product, $unit, $pricing);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function validPricingForProduct(Product $product, User $user): array
    {
        if ($product->disponibile === false) {
            throw ValidationException::withMessages([
                'cart' => 'Il carrello contiene un prodotto non disponibile.',
            ]);
        }

        $pricing = PrezziService::prezzoVisibile($product, $user);

        if (($pricing['ordinabile'] ?? false) !== true || ($pricing['prezzo'] ?? null) === null) {
            throw ValidationException::withMessages([
                'cart' => 'Il carrello contiene un prodotto non ordinabile o senza prezzo valido.',
            ]);
        }

        return $pricing;
    }

    /**
     * @param  array<string, mixed>  $pricing
     */
    private function grossUnitPriceForUnit(Product $product, string $unit, array $pricing): float
    {
        $grossUnitPrice = $this->normalizeDecimal($pricing['prezzo_lordo'] ?? $pricing['prezzo'] ?? 0);

        if ($grossUnitPrice <= 0.0) {
            throw ValidationException::withMessages([
                'cart' => 'Il carrello contiene un prodotto senza prezzo valido.',
            ]);
        }

        if ($unit === ($product->unita_misura ?? $unit)) {
            return $grossUnitPrice;
        }

        try {
            return round($product->priceForUnit($unit, $grossUnitPrice), 4);
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'cart' => 'Il carrello contiene un confezionamento non valido.',
            ]);
        }
    }

    private function hasOutboundActivity(Ordine $ordine): bool
    {
        return $ordine->igroup_sent_at !== null
            || ($ordine->odoo_lead_id !== null && $ordine->odoo_lead_id > 0)
            || $ordine->odoo_synced_at !== null;
    }

    private function normalizeConfirmationNumber(string $confirmationNumber): string
    {
        $confirmationNumber = trim($confirmationNumber);

        if ($confirmationNumber === '' || !preg_match('/^[0-9]+$/', $confirmationNumber)) {
            throw ValidationException::withMessages([
                'confirmation_number' => 'Inserisci un numero di conferma ordine valido.',
            ]);
        }

        return $confirmationNumber;
    }

    private function normalizeRequiredProductId(mixed $value): int
    {
        if (is_numeric($value) && (int) $value > 0) {
            return (int) $value;
        }

        throw ValidationException::withMessages([
            'cart' => 'Il carrello contiene un prodotto senza identificativo valido.',
        ]);
    }

    private function normalizeUnit(mixed $value, ?string $fallbackUnit): string
    {
        $unit = trim((string) ($value ?? $fallbackUnit ?? 'NR'));

        return $unit !== '' ? $unit : 'NR';
    }

    private function normalizeDecimal(mixed $value): float
    {
        if (is_numeric($value)) {
            return round((float) $value, 4);
        }

        return 0.0;
    }

    private function resolveCentroCostoId(User $user): ?int
    {
        $centroCostoId = $user->centro_costo_default_id ?? $user->centro_costo_id ?? null;

        if (is_numeric($centroCostoId) && (int) $centroCostoId > 0) {
            return (int) $centroCostoId;
        }

        return null;
    }
}
