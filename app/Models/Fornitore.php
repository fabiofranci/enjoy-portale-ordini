<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Fornitore extends Model
{
    protected $table = 'fornitori';

    protected $fillable = [
        'nome',
        'partita_iva',
        'email',
        'telefono',
        'indirizzo',
    ];

    public function listini()
    {
        return $this->hasMany(\App\Models\Listino::class, 'fornitore_id');
    }
}
