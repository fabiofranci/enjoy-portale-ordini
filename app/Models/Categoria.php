<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Categoria extends Model
{
    protected $table = 'Categorie';

    protected $fillable = ['nome', 'categoria_padre_id'];

    public function padre(): BelongsTo
    {
        return $this->belongsTo(Categoria::class, 'categoria_padre_id');
    }

    public function figli(): HasMany
    {
        return $this->hasMany(Categoria::class, 'categoria_padre_id');
    }
}
