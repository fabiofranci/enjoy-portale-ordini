<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use SoftDeletes;

    protected $table = 'Prodotti';

    protected $fillable = [
        'nome',
        'codice',
        'categoria_id',
        'unita_misura',
        'descrizione',
        'immagine',
        'pdf_sicurezza',
        'disponibile',
    ];

    public function listini(): BelongsToMany
    {
        return $this->belongsToMany(Listino::class, 'listino_prodotto')
            ->withPivot([
                'prezzo_listino',
                'prezzo_base',
                'prezzo_acquisto',
                'sconto',
                'coeff_provvigione',
                'provvigione_agente',
                'maggiorazione_carta',
            ])
            ->withTimestamps();
    }
}
