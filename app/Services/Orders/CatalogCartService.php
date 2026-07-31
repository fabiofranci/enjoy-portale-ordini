<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Models\ListinoReferenza;
use App\Models\User;
use App\Services\Catalog\CatalogoClienteService;
use Illuminate\Validation\ValidationException;

final class CatalogCartService
{
    public const SESSION_KEY = 'catalog.cart';

    public function __construct(
        private readonly CatalogoClienteService $catalogo,
    ) {}

    public function add(User $user, int $centroCostoId, int $listinoReferenzaId): void
    {
        $cart = $this->raw();
        $currentCenterId = $this->centerId($cart);

        if ($currentCenterId !== null && $currentCenterId !== $centroCostoId && $this->itemRows($cart) !== []) {
            throw ValidationException::withMessages([
                'cart' => 'Il carrello contiene articoli di un altro centro di costo. Svuotalo prima di cambiare centro.',
            ]);
        }

        $centroCosto = $this->catalogo->centroAccessibile($user, $centroCostoId);
        $price = $this->catalogo->query($centroCosto)
            ->whereKey($listinoReferenzaId)
            ->first();

        if (! $price instanceof ListinoReferenza) {
            throw ValidationException::withMessages([
                'cart' => 'L\'articolo selezionato non e piu disponibile nel listino del centro.',
            ]);
        }

        $items = $this->itemRows($cart);
        $key = (string) $price->getKey();
        $quantity = min(9999, max(0, (int) ($items[$key]['quantita'] ?? 0)) + 1);
        $items[$key] = [
            'listino_referenza_id' => (int) $price->getKey(),
            'quantita' => $quantity,
        ];

        session()->put(self::SESSION_KEY, [
            'centro_costo_id' => $centroCostoId,
            'items' => $items,
        ]);
    }

    public function updateQuantity(User $user, string $key, int $quantity): void
    {
        $cart = $this->raw();
        $items = $this->itemRows($cart);

        if (! isset($items[$key])) {
            throw ValidationException::withMessages(['cart' => 'La riga del carrello non esiste.']);
        }

        if ($quantity <= 0) {
            unset($items[$key]);
        } else {
            $items[$key]['quantita'] = min(9999, $quantity);
        }

        session()->put(self::SESSION_KEY, [
            'centro_costo_id' => $this->requiredCenterId($cart),
            'items' => $items,
        ]);

        if ($items !== []) {
            $this->contents($user);
        }
    }

    public function remove(string $key): void
    {
        $cart = $this->raw();
        $items = $this->itemRows($cart);
        unset($items[$key]);

        if ($items === []) {
            $this->clear();

            return;
        }

        session()->put(self::SESSION_KEY, [
            'centro_costo_id' => $this->requiredCenterId($cart),
            'items' => $items,
        ]);
    }

    public function clear(): void
    {
        session()->forget(self::SESSION_KEY);
    }

    public function isEmpty(): bool
    {
        return $this->itemRows($this->raw()) === [];
    }

    public function selectedCenterId(): ?int
    {
        return $this->centerId($this->raw());
    }

    public function quantity(): int
    {
        return array_sum(array_map(
            static fn (array $item): int => max(0, (int) ($item['quantita'] ?? 0)),
            $this->itemRows($this->raw()),
        ));
    }

    /**
     * @return array{
     *     centro_costo_id:int,
     *     centro_costo_nome:string,
     *     fornitore_id:int,
     *     fornitore_code:string,
     *     items:array<string, array<string, int|float|string|null>>,
     *     totale:float
     * }
     */
    public function contents(User $user): array
    {
        $cart = $this->raw();
        $centroCostoId = $this->requiredCenterId($cart);
        $rawItems = $this->itemRows($cart);

        if ($rawItems === []) {
            throw ValidationException::withMessages(['cart' => 'Il carrello e vuoto.']);
        }

        $centroCosto = $this->catalogo->centroAccessibile($user, $centroCostoId);
        $supplier = $centroCosto->fornitoreEffettivo();

        if ($supplier === null) {
            throw ValidationException::withMessages([
                'cart' => 'Il centro di costo non ha un fornitore configurato.',
            ]);
        }

        $ids = collect($rawItems)
            ->pluck('listino_referenza_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values();
        $prices = $this->catalogo->query($centroCosto)
            ->whereIn('listino_referenze.id', $ids)
            ->get()
            ->keyBy('id');

        if ($ids->count() !== count($rawItems) || $prices->count() !== $ids->count()) {
            throw ValidationException::withMessages([
                'cart' => 'Uno o piu articoli non sono piu disponibili nel listino del centro.',
            ]);
        }

        $items = [];
        $total = 0.0;

        foreach ($rawItems as $key => $rawItem) {
            $price = $prices->get((int) $rawItem['listino_referenza_id']);

            if (! $price instanceof ListinoReferenza || $price->referenza === null || $price->listino === null) {
                throw ValidationException::withMessages(['cart' => 'Il carrello contiene una referenza non valida.']);
            }

            $quantity = min(9999, max(1, (int) ($rawItem['quantita'] ?? 1)));
            $unitPrice = round((float) $price->prezzo, 5);
            $lineTotal = round($unitPrice * $quantity, 2);
            $total += $lineTotal;
            $items[(string) $key] = [
                'listino_referenza_id' => (int) $price->getKey(),
                'nome' => (string) $price->referenza->descrizione,
                'supplier_code' => (string) $price->referenza->supplier_code,
                'customer_article_code' => $price->referenza->customer_article_code,
                'listino_nome' => (string) $price->listino->nome_listino,
                'unita' => $price->price_unit ?: ($price->referenza->sales_unit ?: 'NR'),
                'prezzo_unitario' => $unitPrice,
                'quantita' => $quantity,
                'totale' => $lineTotal,
            ];
        }

        return [
            'centro_costo_id' => (int) $centroCosto->getKey(),
            'centro_costo_nome' => (string) $centroCosto->nome,
            'fornitore_id' => (int) $supplier->getKey(),
            'fornitore_code' => (string) $supplier->code,
            'items' => $items,
            'totale' => round($total, 2),
        ];
    }

    /** @return array<string, mixed> */
    private function raw(): array
    {
        $cart = session()->get(self::SESSION_KEY, []);

        return is_array($cart) ? $cart : [];
    }

    /** @param array<string, mixed> $cart */
    private function centerId(array $cart): ?int
    {
        $id = $cart['centro_costo_id'] ?? null;

        return is_numeric($id) && (int) $id > 0 ? (int) $id : null;
    }

    /** @param array<string, mixed> $cart */
    private function requiredCenterId(array $cart): int
    {
        $id = $this->centerId($cart);

        if ($id === null) {
            throw ValidationException::withMessages(['cart' => 'Seleziona un centro di costo dal catalogo.']);
        }

        return $id;
    }

    /**
     * @param array<string, mixed> $cart
     * @return array<string, array<string, mixed>>
     */
    private function itemRows(array $cart): array
    {
        $items = $cart['items'] ?? [];

        return is_array($items) ? $items : [];
    }
}
