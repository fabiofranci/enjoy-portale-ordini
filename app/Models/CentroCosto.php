<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class CentroCosto extends Model
{
    protected $table = 'centri_costo';

    protected $fillable = [
        'cliente_id',
        'nome',
        'descrizione',
        'budget_annuale',
        'budget_mensile',
    ];

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'cliente_id');
    }

    public function listini(): BelongsToMany
    {
        return $this->belongsToMany(Listino::class, 'centro_costo_listino')->withTimestamps();
    }
}
