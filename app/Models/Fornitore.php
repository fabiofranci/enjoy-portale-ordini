<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Fornitore extends Model
{
    protected $table = 'fornitori';

    protected $fillable = [
        'code',
        'nome',
        'partita_iva',
        'email',
        'telefono',
        'indirizzo',
    ];

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    public function listini(): HasMany
    {
        return $this->hasMany(Listino::class, 'fornitore_id');
    }

    public function referenze(): HasMany
    {
        return $this->hasMany(ReferenzaFornitore::class, 'fornitore_id');
    }

    public function importBatches(): HasMany
    {
        return $this->hasMany(ImportBatch::class, 'fornitore_id');
    }

    protected function code(): Attribute
    {
        return Attribute::make(
            set: static fn (string $value): string => strtoupper(trim($value)),
        );
    }
}
