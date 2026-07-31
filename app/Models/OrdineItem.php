<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrdineItem extends Model
{
    protected $table = 'ordine_items';

    protected $fillable = [
        'ordine_id', 'prodotto_id', 'listino_referenza_id',
        'fornitore_code', 'supplier_code', 'customer_article_code', 'descrizione', 'listino_nome',
        'unita', 'quantita',
        'prezzo_unitario_lordo', 'sconto_percentuale', 'iva_percentuale',
        'totale_riga_netto', 'totale_riga_iva', 'totale_riga_lordo',
    ];

    protected $casts = [
        'quantita' => 'integer',
        'prezzo_unitario_lordo' => 'decimal:4',
        'sconto_percentuale' => 'decimal:2',
        'iva_percentuale' => 'decimal:2',
        'totale_riga_netto' => 'decimal:2',
        'totale_riga_iva' => 'decimal:2',
        'totale_riga_lordo' => 'decimal:2',
    ];

    public function prodotto(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'prodotto_id')->withTrashed();
    }

    public function listinoReferenza(): BelongsTo
    {
        return $this->belongsTo(ListinoReferenza::class, 'listino_referenza_id');
    }

    public function calcolaTotali(): void
    {
        $prezzoScontato = (float) $this->prezzo_unitario_lordo * (1 - ((float) $this->sconto_percentuale / 100));
        $lordo = round($prezzoScontato * $this->quantita, 2);
        $ivaPercentuale = $this->iva_percentuale;

        if ($ivaPercentuale === null) {
            $this->totale_riga_netto = null;
            $this->totale_riga_iva = null;
            $this->totale_riga_lordo = $lordo;

            return;
        }

        $netto = round($lordo / (1 + ((float) $ivaPercentuale / 100)), 2);
        $iva = round($lordo - $netto, 2);

        $this->totale_riga_netto = $netto;
        $this->totale_riga_iva = $iva;
        $this->totale_riga_lordo = $lordo;
    }
}
