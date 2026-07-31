<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class ReferenzaFornitore extends Model
{
    protected $table = 'referenze_fornitore';

    protected $fillable = [
        'fornitore_id',
        'supplier_code',
        'customer_article_code',
        'external_source_id',
        'descrizione',
        'descrizione_estesa',
        'categoria',
        'sales_unit',
        'ordinabile',
        'motivo_non_ordinabile',
        'immagine_path',
        'immagine_hash',
        'source_profile',
        'source_hash',
        'source_metadata',
    ];

    protected $casts = [
        'ordinabile' => 'boolean',
        'source_metadata' => 'array',
    ];

    public function fornitore(): BelongsTo
    {
        return $this->belongsTo(Fornitore::class, 'fornitore_id');
    }

    public function packagings(): HasMany
    {
        return $this->hasMany(ReferenzaPackaging::class, 'referenza_fornitore_id');
    }

    public function prezziListino(): HasMany
    {
        return $this->hasMany(ListinoReferenza::class, 'referenza_fornitore_id');
    }

    public function getImmagineUrlAttribute(): ?string
    {
        return $this->immagine_path !== null
            ? Storage::disk('public')->url($this->immagine_path)
            : null;
    }
}
