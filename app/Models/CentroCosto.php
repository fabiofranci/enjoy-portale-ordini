<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection as SupportCollection;

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
        $listinoIds = $this->listiniCommerciali()
            ->where('Listini.attivo', true)
            ->whereHas('fornitore', static fn ($query) => $query->where('attivo', true))
            ->pluck('Listini.id');
        $references = ListinoReferenza::query()
            ->with(['listino.fornitore', 'referenza.packagings'])
            ->whereIn('listino_id', $listinoIds)
            ->where('ordinabile', true)
            ->whereNotNull('prezzo')
            ->whereHas('referenza', static fn ($query) => $query->where('ordinabile', true))
            ->get();
        $duplicates = $references
            ->groupBy('referenza_fornitore_id')
            ->filter(static fn (SupportCollection $items): bool => $items->count() > 1);

        if ($duplicates->isNotEmpty()) {
            throw new DomainException(
                'Il centro di costo contiene referenze duplicate tra i listini assegnati.'
            );
        }

        return $references;
    }
}
