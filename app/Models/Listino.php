<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Listino extends Model
{
    protected $table = 'listini';

    protected $fillable = [
        'id',
        'nome_listino',
        'valido_dal',
        'valido_al',
    ];

    public function prodotti(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'listino_prodotto')
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

    public function centriCosto(): BelongsToMany
    {
        return $this->belongsToMany(CentroCosto::class, 'centro_costo_listino')->withTimestamps();
    }
}
