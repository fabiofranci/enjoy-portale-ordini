<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductPackaging extends Model
{
    protected $table = 'product_packagings';

    protected $fillable = [
        'product_id',
        'from_unit',   // CF
        'to_unit',     // NR
        'multiplier',  // 6
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
