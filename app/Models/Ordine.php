<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ordine extends Model
{
    public const STATUS_NEW = 'nuovo';

    public const STATUS_FULFILLED = 'evaso';

    public const PRIORITY_STANDARD = 'standard';

    public const PRIORITY_URGENT = 'urgente';

    protected $table = 'ordini';

    protected $fillable = [
        'user_id', 'centro_costo_id', 'fornitore_id',
        'cliente_nome', 'cliente_partita_iva', 'centro_costo_nome', 'fornitore_code',
        'stato', 'data_ordine', 'inviato_da_nome', 'inviato_da_email',
        'riferimento_cliente', 'riferimento_richiedente', 'priorita',
        'indirizzo_destinazione', 'orari_consegna', 'note', 'extra_budget',
        'totale_lordo', 'totale_netto', 'iva_totale', 'pdf_path',
        'email_stato', 'email_sent_at', 'email_recipients',
        'odoo_lead_id', 'igroup_sent_at', 'odoo_synced_at',
    ];

    protected $casts = [
        'extra_budget' => 'boolean',
        'data_ordine' => 'datetime',
        'totale_lordo' => 'decimal:2',
        'totale_netto' => 'decimal:2',
        'iva_totale' => 'decimal:2',
        'email_sent_at' => 'datetime',
        'email_recipients' => 'array',
        'odoo_lead_id' => 'integer',
        'igroup_sent_at' => 'datetime',
        'odoo_synced_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(OrdineItem::class, 'ordine_id');
    }

    public function isBozza(): bool
    {
        return $this->stato === 'bozza';
    }

    public function isEvaso(): bool
    {
        return $this->stato === self::STATUS_FULFILLED;
    }

    public function statoLabel(): string
    {
        return match ($this->stato) {
            self::STATUS_NEW => 'Nuovo',
            self::STATUS_FULFILLED => 'Evaso',
            'bozza' => 'Bozza',
            'inviato' => 'Inviato',
            'in_attesa_approvazione' => 'In attesa approvazione',
            'rifiutato' => 'Rifiutato',
            'approvato' => 'Approvato',
            default => ucfirst(str_replace('_', ' ', (string) $this->stato)),
        };
    }

    public function prioritaLabel(): string
    {
        return $this->priorita === self::PRIORITY_URGENT ? 'Urgente' : 'Standard';
    }

    public function ricalcolaTotali(): void
    {
        $hasCompleteTaxBreakdown = $this->items->every(
            static fn (OrdineItem $item): bool => $item->totale_riga_netto !== null
                && $item->totale_riga_iva !== null
        );

        $this->update([
            'totale_netto' => $hasCompleteTaxBreakdown ? $this->items->sum('totale_riga_netto') : null,
            'iva_totale' => $hasCompleteTaxBreakdown ? $this->items->sum('totale_riga_iva') : null,
            'totale_lordo' => $this->items->sum('totale_riga_lordo'),
        ]);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function centroCosto(): BelongsTo
    {
        return $this->belongsTo(CentroCosto::class);
    }

    public function fornitore(): BelongsTo
    {
        return $this->belongsTo(Fornitore::class);
    }
}
