<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'odoo_id',
        'odoo_write_date',
        'attivo',
        'sequenza',
        'odoo_currency_id',
        'odoo_currency_name',
        'odoo_company_id',
        'odoo_company_name',
    ];

    public function fornitore()
    {
        return $this->belongsTo(Fornitore::class, 'fornitore_id');
    }

    protected $casts = [
        'valido_dal' => 'date',
        'valido_al'  => 'date',
        'odoo_id' => 'integer',
        'odoo_write_date' => 'datetime',
        'attivo' => 'boolean',
        'sequenza' => 'integer',
        'odoo_currency_id' => 'integer',
        'odoo_company_id' => 'integer',
    ];

    public function centroCosto(): BelongsTo
    {
        return $this->belongsTo(CentroCosto::class, 'centro_costo_id');
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class, 'categoria_id');
    }

    public function centriCosto(): BelongsToMany
    {
        return $this->belongsToMany(CentroCosto::class, 'centro_costo_listino')->withTimestamps();
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'listino_prodotto', 'listino_id', 'product_id')
            ->withPivot([
                'prezzo_lordo',
                'sconto_percentuale',
                'prezzo',
                'iva_percentuale',
                'ordinabile',
                'motivo_non_ordinabile',
                'prezzo_sorgente',
                'unita_prezzo_sorgente',
            ])
            ->withTimestamps();
    }

    public function odooItems(): HasMany
    {
        return $this->hasMany(ListinoItem::class, 'listino_id');
    }
}
