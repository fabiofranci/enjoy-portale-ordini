<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrdineItem extends Model
{
    protected $table = 'ordine_items';

    protected $fillable = [
        'ordine_id','prodotto_id','quantita',
        'prezzo_unitario_lordo','sconto_percentuale','iva_percentuale',
        'totale_riga_netto','totale_riga_iva','totale_riga_lordo',
    ];

    public function calcolaTotali(): void
    {
        $prezzoScontato = $this->prezzo_unitario_lordo * (1 - ($this->sconto_percentuale / 100));
        $netto = round($prezzoScontato * $this->quantita / (1 + $this->iva_percentuale/100), 2);
        $lordo = round($prezzoScontato * $this->quantita, 2);
        $iva   = round($lordo - $netto, 2);

        $this->totale_riga_netto = $netto;
        $this->totale_riga_iva   = $iva;
        $this->totale_riga_lordo = $lordo;
    }
}
