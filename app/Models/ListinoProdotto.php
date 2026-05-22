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
    ];

    public function prodotto(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
