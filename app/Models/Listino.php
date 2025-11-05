<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Listino extends Model
{
    protected $table = 'Listini';

    protected $fillable = [
        'nome_listino',
        'tipo',
        'fornitore_id',
        'centro_costo_id',
        'categoria_id',
        'sconto_percentuale',
        'valido_dal',
        'valido_al',
    ];

    public function fornitore()
    {
        return $this->belongsTo(Fornitore::class, 'fornitore_id');
    }

    protected $casts = [
        'valido_dal' => 'date',
        'valido_al'  => 'date',
    ];

    public function centroCosto(): BelongsTo
    {
        return $this->belongsTo(CentroCosto::class, 'centro_costo_id');
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class, 'categoria_id');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'listino_prodotto', 'listino_id', 'product_id')
            ->withPivot(['prezzo_lordo', 'sconto_percentuale', 'prezzo', 'iva_percentuale'])
            ->withTimestamps();
    }
}
