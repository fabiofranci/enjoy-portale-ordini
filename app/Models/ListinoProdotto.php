<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ListinoProdotto extends Model
{
    protected $table = 'listino_prodotto';

    protected $fillable = [
        'listino_id',
        'product_id',
        'prezzo_lordo',
        'sconto_percentuale',
        'prezzo',
        'iva_percentuale',
        'ordinabile',
        'motivo_non_ordinabile',
        'prezzo_sorgente',
        'unita_prezzo_sorgente',
    ];

    protected $casts = [
        'prezzo_lordo' => 'decimal:5',
        'sconto_percentuale' => 'decimal:2',
        'prezzo' => 'decimal:5',
        'iva_percentuale' => 'decimal:2',
        'ordinabile' => 'boolean',
        'prezzo_sorgente' => 'decimal:5',
    ];

    public function prodotto(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
