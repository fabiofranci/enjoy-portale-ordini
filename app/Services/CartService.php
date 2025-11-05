<?php

namespace App\Services;

use App\Models\Ordine;
use App\Models\OrdineItem;
use App\Models\Prodotto;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CartService
{
    public static function getDraftFor(User $user): Ordine
    {
        /** @var Ordine $ordine */
        $ordine = Ordine::firstOrCreate([
            'user_id' => $user->id,
            'stato'   => 'bozza',
        ]);

        // assicura relazione caricata
        $ordine->load('items');

        return $ordine;
    }

    public static function addProduct(User $user, int $productId, int $qty = 1): Ordine
    {
        $ordine = self::getDraftFor($user);
        $product = Prodotto::findOrFail($productId);

        $pricing = PrezziService::prezzoVisibile($product, $user);

        $item = $ordine->items()->firstOrNew(['prodotto_id' => $product->id]);
        $item->quantita = max(1, (int) $item->quantita + $qty);
        $item->prezzo_unitario_lordo = $pricing['prezzo_lordo'];
        $item->sconto_percentuale    = $pricing['sconto_percentuale'];
        $item->iva_percentuale       = $pricing['iva_percentuale'];
        $item->calcolaTotali();
        $item->save();

        $ordine->refresh()->load('items');
        $ordine->ricalcolaTotali();

        return $ordine;
    }

    public static function updateQty(User $user, int $productId, int $qty): Ordine
    {
        $ordine = self::getDraftFor($user);
        $item = $ordine->items()->where('prodotto_id', $productId)->firstOrFail();
        $item->quantita = max(1, (int) $qty);
        $item->calcolaTotali();
        $item->save();

        $ordine->refresh()->load('items');
        $ordine->ricalcolaTotali();

        return $ordine;
    }

    public static function removeProduct(User $user, int $productId): Ordine
    {
        $ordine = self::getDraftFor($user);
        $ordine->items()->where('prodotto_id', $productId)->delete();
        $ordine->refresh()->load('items');
        $ordine->ricalcolaTotali();
        return $ordine;
    }

    public static function empty(User $user): void
    {
        $ordine = self::getDraftFor($user);
        $ordine->items()->delete();
        $ordine->ricalcolaTotali();
    }
}
