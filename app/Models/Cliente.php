<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cliente extends Model
{
    protected $table = 'clienti';

    protected $fillable = [
        'nome',
        'partita_iva',
        'email',
        'telefono',
        'indirizzo',
    ];

    public function centriCosto(): HasMany
    {
        return $this->hasMany(CentroCosto::class, 'cliente_id');
    }
}
