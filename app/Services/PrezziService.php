<?php

namespace App\Services;

use App\Models\Prodotto;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Arr;

class PrezziService
{
    /**
     * Ritorna il prezzo da mostrare e i parametri fiscali (lordo/sconto/iva)
     * Logica semplificata: cerca un listino attivo associato al centro di costo
     * dell'utente; in fallback usa il listino base del cliente o il prezzo del prodotto.
     */
    public static function prezzoVisibile(Prodotto $product, User $user): array
    {
        $oggi = Carbon::now()->toDateString();

        // TODO: ricava il centro costo "predefinito" dell'utente o selezionato
        $centroCostoId = $user->centro_costo_default_id ?? null;

        // 1) prova listino associato a centro costo
        if ($centroCostoId) {
            $record = $product->listini()
                ->wherePivot('valido_dal', '<=', $oggi)
                ->where(function ($q) use ($oggi) {
                    $q->whereNull('valido_al')->orWhere('valido_al', '>=', $oggi);
                })
                ->whereHas('centriCosto', fn($q) => $q->where('centri_costo.id', $centroCostoId))
                ->first();
            if ($record) {
                return [
                    'prezzo_lordo'       => (float) $record->pivot->prezzo_lordo,
                    'sconto_percentuale' => (float) ($record->pivot->sconto_percentuale ?? 0),
                    'iva_percentuale'    => (float) ($record->pivot->iva_percentuale ?? 22),
                ];
            }
        }

        // 2) fallback: primo listino attivo del cliente
        $record = $product->listini()
            ->wherePivot('valido_dal', '<=', $oggi)
            ->where(function ($q) use ($oggi) {
                $q->whereNull('valido_al')->orWhere('valido_al', '>=', $oggi);
            })
            ->first();

        if ($record) {
            return [
                'prezzo_lordo'       => (float) $record->pivot->prezzo_lordo,
                'sconto_percentuale' => (float) ($record->pivot->sconto_percentuale ?? 0),
                'iva_percentuale'    => (float) ($record->pivot->iva_percentuale ?? 22),
            ];
        }

        // 3) fallback estremo: campi prezzo sul prodotto (se presenti)
        return [
            'prezzo_lordo'       => (float) ($product->prezzo_listino_ufficiale ?? 0),
            'sconto_percentuale' => 0.0,
            'iva_percentuale'    => 22.0,
        ];
    }
}
