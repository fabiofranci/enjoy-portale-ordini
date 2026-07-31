<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Models\CentroCosto;
use App\Models\Fornitore;
use App\Models\ListinoReferenza;
use App\Models\Ordine;
use App\Models\OrdineItem;
use App\Models\User;
use App\Services\Catalog\CatalogoClienteService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

final class OrderSubmissionService
{
    public function __construct(
        private readonly CatalogoClienteService $catalogo,
        private readonly OrderQuotePdfService $pdfService,
        private readonly SupplierOrderMailService $mailService,
    ) {}

    /**
     * @param  array<string, array<string, mixed>>  $cartItems
     */
    public function submit(
        User $user,
        int $centroCostoId,
        array $cartItems,
        string $customerReference,
        ?string $notes = null,
    ): Ordine {
        $customerReference = $this->normalizeReference($customerReference);
        $notes = $this->normalizeNotes($notes);
        [$centroCosto, $supplier, $prices, $quantities] = $this->validatedOrderData(
            $user,
            $centroCostoId,
            $cartItems,
        );

        try {
            $ordine = DB::transaction(function () use (
                $user,
                $centroCosto,
                $supplier,
                $prices,
                $quantities,
                $customerReference,
                $notes,
            ): Ordine {
                if (Ordine::query()
                    ->where('user_id', $user->getKey())
                    ->where('riferimento_cliente', $customerReference)
                    ->lockForUpdate()
                    ->exists()) {
                    throw ValidationException::withMessages([
                        'confirmation_number' => 'Questo numero ordine cliente e gia stato utilizzato.',
                    ]);
                }

                $ordine = Ordine::query()->create([
                    'user_id' => $user->getKey(),
                    'centro_costo_id' => $centroCosto->getKey(),
                    'fornitore_id' => $supplier->getKey(),
                    'cliente_nome' => $user->cliente?->nome ?? $user->name,
                    'cliente_partita_iva' => $user->cliente?->partita_iva,
                    'centro_costo_nome' => $centroCosto->nome,
                    'fornitore_code' => $supplier->code,
                    'stato' => 'inviato',
                    'riferimento_cliente' => $customerReference,
                    'note' => $notes,
                    'extra_budget' => false,
                    'totale_lordo' => 0,
                    'totale_netto' => null,
                    'iva_totale' => null,
                    'pdf_path' => null,
                    'email_stato' => 'in_attesa',
                    'email_sent_at' => null,
                    'email_recipients' => null,
                ]);

                $this->createItems($ordine, $prices, $quantities, $supplier);
                $ordine->load('items');
                $ordine->ricalcolaTotali();

                return $ordine;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'confirmation_number' => 'Questo numero ordine cliente e gia stato utilizzato.',
            ]);
        }

        $ordine->loadMissing(['user.cliente', 'centroCosto', 'fornitore', 'items']);

        try {
            $pdf = $this->pdfService->generate($ordine);
            $this->mailService->send($ordine, $pdf['path'], $pdf['filename']);
        } catch (Throwable $exception) {
            $ordine->forceFill(['email_stato' => 'errore'])->save();

            Log::error('Order email delivery failed after persistence', [
                'ordine_id' => $ordine->getKey(),
                'exception' => $exception,
            ]);
        }

        Log::info('Customer binding order persisted', [
            'ordine_id' => $ordine->getKey(),
            'supplier_code' => $supplier->code,
            'email_stato' => $ordine->fresh()?->email_stato,
        ]);

        return $ordine->fresh(['user.cliente', 'centroCosto', 'fornitore', 'items']) ?? $ordine;
    }

    /**
     * @param  array<string, array<string, mixed>>  $cartItems
     * @return array{0:CentroCosto,1:Fornitore,2:\Illuminate\Support\Collection<int, ListinoReferenza>,3:array<int, int>}
     */
    private function validatedOrderData(
        User $user,
        int $centroCostoId,
        array $cartItems,
    ): array {
        if ($cartItems === []) {
            throw ValidationException::withMessages(['cart' => 'Il carrello e vuoto.']);
        }

        $centroCosto = $this->catalogo->centroAccessibile($user, $centroCostoId);
        $supplier = $centroCosto->fornitoreEffettivo();

        if (! $supplier instanceof Fornitore) {
            throw ValidationException::withMessages([
                'cart' => 'Il centro di costo non ha un fornitore configurato.',
            ]);
        }

        $quantities = [];

        foreach ($cartItems as $item) {
            $priceId = $item['listino_referenza_id'] ?? null;
            $quantity = $item['quantita'] ?? null;

            if (! is_numeric($priceId) || (int) $priceId < 1 || ! is_numeric($quantity) || (int) $quantity < 1) {
                throw ValidationException::withMessages(['cart' => 'Il carrello contiene dati non validi.']);
            }

            $quantities[(int) $priceId] = min(9999, (int) $quantity);
        }

        if (count($quantities) !== count($cartItems)) {
            throw ValidationException::withMessages(['cart' => 'Il carrello contiene righe duplicate.']);
        }

        $prices = $this->catalogo->query($centroCosto)
            ->whereIn('listino_referenze.id', array_keys($quantities))
            ->get()
            ->keyBy('id');

        if ($prices->count() !== count($quantities)) {
            throw ValidationException::withMessages([
                'cart' => 'Uno o piu articoli non sono piu disponibili nel listino del centro.',
            ]);
        }

        return [$centroCosto, $supplier, $prices, $quantities];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, ListinoReferenza>  $prices
     * @param  array<int, int>  $quantities
     */
    private function createItems(
        Ordine $ordine,
        \Illuminate\Support\Collection $prices,
        array $quantities,
        Fornitore $supplier,
    ): void {
        foreach ($prices as $price) {
            $reference = $price->referenza;
            $listino = $price->listino;

            if ($reference === null || $listino === null) {
                throw ValidationException::withMessages(['cart' => 'Una referenza non contiene i dati richiesti.']);
            }

            $item = new OrdineItem([
                'prodotto_id' => null,
                'listino_referenza_id' => $price->getKey(),
                'fornitore_code' => $supplier->code,
                'supplier_code' => $reference->supplier_code,
                'customer_article_code' => $reference->customer_article_code,
                'descrizione' => $reference->descrizione,
                'listino_nome' => $listino->nome_listino,
                'unita' => $price->price_unit ?: ($reference->sales_unit ?: 'NR'),
                'quantita' => $quantities[(int) $price->getKey()],
                'prezzo_unitario_lordo' => $price->prezzo,
                'sconto_percentuale' => 0,
                'iva_percentuale' => $price->iva_percentuale,
            ]);
            $item->calcolaTotali();
            $ordine->items()->save($item);
        }
    }

    private function normalizeReference(string $reference): string
    {
        $reference = trim($reference);

        if ($reference === '' || mb_strlen($reference) > 50 || preg_match('/[\r\n]/', $reference)) {
            throw ValidationException::withMessages([
                'confirmation_number' => 'Inserisci un numero ordine cliente valido, massimo 50 caratteri.',
            ]);
        }

        return $reference;
    }

    private function normalizeNotes(?string $notes): ?string
    {
        $notes = trim((string) $notes);

        if (mb_strlen($notes) > 1000) {
            throw ValidationException::withMessages(['notes' => 'Le note non possono superare 1000 caratteri.']);
        }

        return $notes !== '' ? $notes : null;
    }
}
