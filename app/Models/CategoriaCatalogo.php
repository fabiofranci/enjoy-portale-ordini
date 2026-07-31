<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class CategoriaCatalogo extends Model
{
    public const FILTER_WITHOUT_CATEGORY = '__without_category__';

    protected $table = 'categorie_catalogo';

    protected $fillable = [
        'fornitore_id',
        'parent_id',
        'codice',
        'nome',
        'slug',
        'attiva',
    ];

    protected $casts = [
        'attiva' => 'boolean',
    ];

    public function fornitore(): BelongsTo
    {
        return $this->belongsTo(Fornitore::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function referenze(): BelongsToMany
    {
        return $this->belongsToMany(
            ReferenzaFornitore::class,
            'referenza_fornitore_categoria',
            'categoria_catalogo_id',
            'referenza_fornitore_id',
        )->withTimestamps();
    }
}
