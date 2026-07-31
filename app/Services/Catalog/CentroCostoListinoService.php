<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\CentroCosto;
use App\Models\Listino;
use App\Services\Catalog\Exceptions\ListinoAssignmentException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class CentroCostoListinoService
{
    /**
     * @param  array<int, int|string>  $listinoIds
     */
    public function assign(CentroCosto $centroCosto, array $listinoIds): void
    {
        if ($listinoIds === []) {
            throw new ListinoAssignmentException('Selezionare almeno un listino.');
        }

        $normalizedIds = [];

        foreach ($listinoIds as $id) {
            if (
                (! is_int($id) && (! is_string($id) || ! ctype_digit($id)))
                || (int) $id < 1
            ) {
                throw new ListinoAssignmentException(
                    'La selezione contiene listini non validi o duplicati.'
                );
            }

            $normalizedIds[] = (int) $id;
        }

        if (count($normalizedIds) !== count(array_unique($normalizedIds))) {
            throw new ListinoAssignmentException(
                'La selezione contiene listini non validi o duplicati.'
            );
        }

        DB::transaction(function () use ($centroCosto, $normalizedIds): void {
            $lockedCentro = CentroCosto::query()
                ->lockForUpdate()
                ->find($centroCosto->getKey());

            if ($lockedCentro === null) {
                throw new ListinoAssignmentException('Centro di costo non trovato.');
            }

            $assignedIds = DB::table('centro_costo_listino')
                ->where('centro_costo_id', $lockedCentro->getKey())
                ->pluck('listino_id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();
            $alreadyAssigned = array_values(array_intersect($normalizedIds, $assignedIds));

            if ($alreadyAssigned !== []) {
                throw new ListinoAssignmentException(
                    'Uno o piu listini selezionati sono gia assegnati al centro di costo.'
                );
            }

            $candidates = Listino::query()
                ->with('fornitore')
                ->whereIn('id', $normalizedIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($candidates->count() !== count($normalizedIds)) {
                throw new ListinoAssignmentException(
                    'Uno o piu listini selezionati non esistono.'
                );
            }

            foreach ($normalizedIds as $listinoId) {
                $this->validateAssignableListino($candidates->get($listinoId));
            }

            $existingCommercialLists = Listino::query()
                ->whereIn('id', $assignedIds)
                ->where('tipo', 'acquisto')
                ->whereNotNull('fornitore_id')
                ->lockForUpdate()
                ->get();
            $supplierIds = $existingCommercialLists
                ->pluck('fornitore_id')
                ->merge($candidates->pluck('fornitore_id'))
                ->map(static fn (mixed $id): int => (int) $id)
                ->unique()
                ->values();

            if ($supplierIds->count() > 1) {
                throw new ListinoAssignmentException(
                    'Tutti i listini commerciali del centro di costo devono appartenere allo stesso fornitore.'
                );
            }

            $allCommercialListIds = $existingCommercialLists
                ->pluck('id')
                ->merge($normalizedIds)
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();
            $overlappingCodes = $this->overlappingReferenceCodes($allCommercialListIds);

            if ($overlappingCodes !== []) {
                $count = count($overlappingCodes);
                $overlapSummary = $count === 1
                    ? '1 referenza e presente'
                    : "{$count} referenze sono presenti";

                throw new ListinoAssignmentException(
                    "Assegnazione bloccata: {$overlapSummary} in piu listini ("
                        .implode(', ', $overlappingCodes).').',
                    $overlappingCodes,
                );
            }

            $now = now();
            DB::table('centro_costo_listino')->insert(array_map(
                static fn (int $listinoId): array => [
                    'centro_costo_id' => $lockedCentro->getKey(),
                    'listino_id' => $listinoId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                $normalizedIds
            ));
        });
    }

    public function remove(CentroCosto $centroCosto, Listino $listino): void
    {
        DB::transaction(function () use ($centroCosto, $listino): void {
            $lockedCentro = CentroCosto::query()
                ->lockForUpdate()
                ->find($centroCosto->getKey());

            if ($lockedCentro === null) {
                throw new ListinoAssignmentException('Centro di costo non trovato.');
            }

            $isCommercialList = Listino::query()
                ->whereKey($listino->getKey())
                ->where('tipo', 'acquisto')
                ->whereNotNull('fornitore_id')
                ->lockForUpdate()
                ->exists();

            if (! $isCommercialList) {
                throw new ListinoAssignmentException(
                    'Il servizio gestisce soltanto assegnazioni di listini commerciali.'
                );
            }

            $deleted = DB::table('centro_costo_listino')
                ->where('centro_costo_id', $lockedCentro->getKey())
                ->where('listino_id', $listino->getKey())
                ->delete();

            if ($deleted === 0) {
                throw new ListinoAssignmentException(
                    'Il listino non risulta assegnato al centro di costo.'
                );
            }
        });
    }

    /**
     * @return Collection<int, Listino>
     */
    public function availableListini(CentroCosto $centroCosto): Collection
    {
        $existingLists = $centroCosto->listiniCommerciali()
            ->select(['Listini.id', 'Listini.fornitore_id'])
            ->get();
        $supplierIds = $existingLists->pluck('fornitore_id')->unique()->values();

        if ($supplierIds->count() > 1) {
            return new Collection;
        }

        $existingReferenceIds = DB::table('listino_referenze')
            ->whereIn('listino_id', $existingLists->pluck('id'))
            ->pluck('referenza_fornitore_id');

        return Listino::query()
            ->with('fornitore')
            ->where('tipo', 'acquisto')
            ->where('attivo', true)
            ->whereNotNull('fornitore_id')
            ->whereHas('fornitore', static fn ($query) => $query->where('attivo', true))
            ->when(
                $supplierIds->isNotEmpty(),
                static fn ($query) => $query->where('fornitore_id', $supplierIds->first())
            )
            ->whereNotIn('id', $existingLists->pluck('id'))
            ->when(
                $existingReferenceIds->isNotEmpty(),
                static fn ($query) => $query->whereDoesntHave(
                    'referenzeListino',
                    static fn ($references) => $references->whereIn(
                        'referenza_fornitore_id',
                        $existingReferenceIds
                    )
                )
            )
            ->orderBy('fornitore_id')
            ->orderBy('nome_listino')
            ->get();
    }

    private function validateAssignableListino(?Listino $listino): void
    {
        if (
            $listino === null
            || $listino->tipo !== 'acquisto'
            || $listino->fornitore_id === null
        ) {
            throw new ListinoAssignmentException(
                'Sono assegnabili soltanto listini commerciali collegati a un fornitore.'
            );
        }

        if ($listino->attivo !== true) {
            throw new ListinoAssignmentException(
                "Il listino {$listino->nome_listino} non e attivo."
            );
        }

        if ($listino->fornitore === null || $listino->fornitore->attivo !== true) {
            throw new ListinoAssignmentException(
                "Il fornitore del listino {$listino->nome_listino} non e attivo."
            );
        }
    }

    /**
     * @param  array<int, int>  $listinoIds
     * @return array<int, string>
     */
    private function overlappingReferenceCodes(array $listinoIds): array
    {
        if (count($listinoIds) < 2) {
            return [];
        }

        return DB::table('listino_referenze')
            ->join(
                'referenze_fornitore',
                'referenze_fornitore.id',
                '=',
                'listino_referenze.referenza_fornitore_id'
            )
            ->whereIn('listino_referenze.listino_id', $listinoIds)
            ->groupBy(
                'listino_referenze.referenza_fornitore_id',
                'referenze_fornitore.supplier_code'
            )
            ->havingRaw('COUNT(DISTINCT listino_referenze.listino_id) > 1')
            ->orderBy('referenze_fornitore.supplier_code')
            ->pluck('referenze_fornitore.supplier_code')
            ->all();
    }
}
