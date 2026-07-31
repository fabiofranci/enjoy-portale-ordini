<?php

namespace App\Models;

use App\Services\Catalog\CatalogoClienteService;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class CentroCosto extends Model
{
    protected $table = 'centri_costo';

    protected $fillable = [
        'cliente_id',
        'nome',
        'descrizione',
        'budget_annuale',
        'budget_mensile',
    ];

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'cliente_id');
    }

    public function listini(): BelongsToMany
    {
        return $this->belongsToMany(Listino::class, 'centro_costo_listino')->withTimestamps();
    }

    public function listiniCommerciali(): BelongsToMany
    {
        return $this->belongsToMany(Listino::class, 'centro_costo_listino')
            ->where('Listini.tipo', 'acquisto')
            ->whereNotNull('Listini.fornitore_id')
            ->withTimestamps();
    }

    public function fornitoreEffettivo(): ?Fornitore
    {
        $supplierIds = $this->listiniCommerciali()
            ->pluck('Listini.fornitore_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        if ($supplierIds->count() > 1) {
            throw new DomainException(
                'Il centro di costo ha listini commerciali di fornitori diversi.'
            );
        }

        return $supplierIds->isEmpty()
            ? null
            : Fornitore::query()->find($supplierIds->first());
    }

    /**
     * @return Collection<int, ListinoReferenza>
     */
    public function referenzeDisponibili(): Collection
    {
        return app(CatalogoClienteService::class)->query($this)->get();
    }
}
