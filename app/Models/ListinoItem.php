<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ListinoItem extends Model
{
    protected $table = 'ListinoItems';

    protected $fillable = [
        'odoo_id',
        'listino_id',
        'odoo_pricelist_id',
        'odoo_write_date',
        'nome_regola',
        'descrizione_prezzo',
        'applied_on',
        'display_applied_on',
        'min_quantity',
        'date_start',
        'date_end',
        'categoria_id',
        'odoo_categoria_id',
        'product_id',
        'odoo_product_tmpl_id',
        'odoo_product_variant_id',
        'base',
        'base_pricelist_id',
        'odoo_base_pricelist_id',
        'compute_price',
        'fixed_price',
        'percent_price',
        'price_discount',
        'price_round',
        'price_surcharge',
        'price_markup',
        'price_min_margin',
        'price_max_margin',
    ];

    protected $casts = [
        'odoo_id' => 'integer',
        'listino_id' => 'integer',
        'odoo_pricelist_id' => 'integer',
        'odoo_write_date' => 'datetime',
        'min_quantity' => 'decimal:4',
        'date_start' => 'datetime',
        'date_end' => 'datetime',
        'categoria_id' => 'integer',
        'odoo_categoria_id' => 'integer',
        'product_id' => 'integer',
        'odoo_product_tmpl_id' => 'integer',
        'odoo_product_variant_id' => 'integer',
        'base_pricelist_id' => 'integer',
        'odoo_base_pricelist_id' => 'integer',
        'fixed_price' => 'decimal:4',
        'percent_price' => 'decimal:4',
        'price_discount' => 'decimal:4',
        'price_round' => 'decimal:4',
        'price_surcharge' => 'decimal:4',
        'price_markup' => 'decimal:4',
        'price_min_margin' => 'decimal:4',
        'price_max_margin' => 'decimal:4',
    ];

    public function listino(): BelongsTo
    {
        return $this->belongsTo(Listino::class, 'listino_id');
    }

    public function baseListino(): BelongsTo
    {
        return $this->belongsTo(Listino::class, 'base_pricelist_id');
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class, 'categoria_id');
    }

    public function prodotto(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
