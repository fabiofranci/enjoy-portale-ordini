<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Product extends Model
{
    use SoftDeletes;

    protected $table = 'Prodotti';

    protected $fillable = [
        'nome',
        'codice',
        'categoria_id',
        'unita_misura', // es. NR
        'descrizione',
        'immagine',
        'pdf_sicurezza',
        'disponibile',
    ];

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class, 'categoria_id');
    }

    public function prezzoListinoBase(): ?float
    {
        return $this->listini()
            ->where('listino_id', 1)
            ->value('prezzo');
    }

    public function salvaPrezzoListinoBase(float $prezzo): void
    {
        $this->listini()->syncWithoutDetaching([
            1 => [
                'prezzo' => $prezzo,
            ],
        ]);
    }

    public function listini()
    {
        return $this->belongsToMany(Listino::class, 'listino_prodotto')
            ->withPivot(['prezzo', 'prezzo_lordo', 'sconto_percentuale', 'iva_percentuale'])
            ->withTimestamps();
    }

public function getPrezzoListinoBase(): ?float
{
    $listino = $this->listini()
        ->where('listino_id', 1)
        ->first();

    return $listino?->pivot?->prezzo !== null
        ? (float) $listino->pivot->prezzo
        : null;
}

    /**
     * Prezzo per il listino attivo (es. listino_id = 1)
     */
    public function prezzoPerListino(int $listinoId): ?float
    {
        return $this->listini
            ->firstWhere('listino_id', $listinoId)
            ?->prezzo;
    }

    /**
     * Prezzo attivo per il cliente (fallback al listino base).
     */
    public function getPrezzoAttivo(): ?float
    {
        if ($this->relationLoaded('listini')) {
            $listino = $this->listini->firstWhere('id', 1);
            if ($listino?->pivot?->prezzo !== null) {
                return (float) $listino->pivot->prezzo;
            }

            $fallbackListino = $this->listini->first();
            if ($fallbackListino?->pivot?->prezzo !== null) {
                return (float) $fallbackListino->pivot->prezzo;
            }
        }

        $prezzo = $this->listini()
            ->wherePivot('listino_id', 1)
            ->value('listino_prodotto.prezzo');

        if ($prezzo !== null) {
            return (float) $prezzo;
        }

        $prezzo = $this->listini()
            ->value('listino_prodotto.prezzo');

        if ($prezzo !== null) {
            return (float) $prezzo;
        }

        if (isset($this->prezzo_listino_ufficiale)) {
            return (float) $this->prezzo_listino_ufficiale;
        }

        return $this->getPrezzoListinoBase();
    }

    public function packagings(): HasMany
    {
        return $this->hasMany(ProductPackaging::class, 'product_id');
    }

    /**
     * Ritorna il moltiplicatore tra due unità
     * es: CF -> NR = 6
     */
    public function packagingMultiplier(string $from, string $to): ?float
    {
        return $this->packagings
            ->where('from_unit', $from)
            ->where('to_unit', $to)
            ->first()
            ?->multiplier;
    }

    /**
     * Calcolo prezzo per unità diversa da quella base
     */
    public function priceForUnit(string $unit, float $basePrice): float
    {
        if ($unit === $this->unita_misura) {
            return $basePrice;
        }

        $multiplier = $this->packagingMultiplier($unit, $this->unita_misura);

        if ($multiplier === null) {
            throw new \RuntimeException("Packaging $unit → {$this->unita_misura} non definito");
        }

        return $basePrice * $multiplier;
    }
}
