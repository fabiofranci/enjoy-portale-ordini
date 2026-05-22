<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ordine extends Model
{
    protected $table = 'ordini';

    protected $fillable = [
        'user_id','centro_costo_id','stato','riferimento_cliente','note','extra_budget',
        'totale_lordo','totale_netto','iva_totale','pdf_path',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(OrdineItem::class, 'ordine_id');
    }

    public function isBozza(): bool
    {
        return $this->stato === 'bozza';
    }

    public function ricalcolaTotali(): void
    {
        $netto = $this->items->sum('totale_riga_netto');
        $iva   = $this->items->sum('totale_riga_iva');
        $lordo = $this->items->sum('totale_riga_lordo');

        $this->update([
            'totale_netto' => $netto,
            'iva_totale'   => $iva,
            'totale_lordo' => $lordo,
        ]);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function centroCosto()
    {
        return $this->belongsTo(CentroCosto::class);
    }

}
