<?php

declare(strict_types=1);

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ListinoReferenza extends Model
{
    protected $table = 'listino_referenze';

    protected $fillable = [
        'listino_id',
        'referenza_fornitore_id',
        'prezzo',
        'prezzo_sorgente',
        'price_unit',
        'prezzo_lordo',
        'sconto_percentuale',
        'iva_percentuale',
        'prezzo_cartone',
        'attivo',
        'ordinabile',
        'motivo_non_ordinabile',
        'modificato_manualmente',
    ];

    protected $casts = [
        'prezzo' => 'decimal:5',
        'prezzo_sorgente' => 'decimal:5',
        'prezzo_lordo' => 'decimal:5',
        'sconto_percentuale' => 'decimal:5',
        'iva_percentuale' => 'decimal:5',
        'prezzo_cartone' => 'decimal:5',
        'attivo' => 'boolean',
        'ordinabile' => 'boolean',
        'modificato_manualmente' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $price): void {
            if ($price->listino_id === null || $price->referenza_fornitore_id === null) {
                return;
            }

            $listinoSupplierId = Listino::query()
                ->whereKey($price->listino_id)
                ->value('fornitore_id');
            $referenceSupplierId = ReferenzaFornitore::query()
                ->whereKey($price->referenza_fornitore_id)
                ->value('fornitore_id');

            if (
                $listinoSupplierId === null
                || $referenceSupplierId === null
                || (int) $listinoSupplierId !== (int) $referenceSupplierId
            ) {
                throw new DomainException(
                    'Listino e referenza devono appartenere allo stesso fornitore.'
                );
            }
        });
    }

    public function listino(): BelongsTo
    {
        return $this->belongsTo(Listino::class, 'listino_id');
    }

    public function referenza(): BelongsTo
    {
        return $this->belongsTo(ReferenzaFornitore::class, 'referenza_fornitore_id');
    }
}
