<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Categoria extends Model
{
    protected $table = 'Categorie';

    protected $fillable = [
        'nome',
        'codice',
        'percentuale_ricarico',
        'categoria_padre_id',
        'odoo_id',
        'odoo_write_date',
    ];

    protected $casts = [
        'odoo_id' => 'integer',
        'odoo_write_date' => 'datetime',
    ];

    public function padre(): BelongsTo
    {
        return $this->belongsTo(Categoria::class, 'categoria_padre_id');
    }

    public function figli(): HasMany
    {
        return $this->hasMany(Categoria::class, 'categoria_padre_id');
    }
}
