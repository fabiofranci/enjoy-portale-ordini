<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\CategoriaCatalogo;
use App\Models\CentroCosto;
use App\Models\ListinoReferenza;
use App\Models\User;
use App\Services\Catalog\Data\CatalogoClienteItem;
use App\Services\Catalog\Exceptions\CatalogoClienteIncoerenteException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class CatalogoClienteService
{
    /**
     * @return EloquentCollection<int, CentroCosto>
     */
    public function centriAccessibili(User $user): EloquentCollection
    {
        if ($user->cliente_id === null) {
            return new EloquentCollection;
        }

        return CentroCosto::query()
            ->where('cliente_id', $user->cliente_id)
            ->orderBy('nome')
            ->get();
    }

    /**
     * @throws AuthorizationException
     */
    public function centroAccessibile(User $user, int $centroCostoId): CentroCosto
    {
        $centroCosto = CentroCosto::query()
            ->whereKey($centroCostoId)
            ->whereNotNull('cliente_id')
            ->where('cliente_id', $user->cliente_id)
            ->first();

        if ($centroCosto === null) {
            throw new AuthorizationException(
                'Il centro di costo richiesto non e accessibile.'
            );
        }

        return $centroCosto;
    }

    /**
     * @return Builder<ListinoReferenza>
     */
    public function query(CentroCosto $centroCosto): Builder
    {
        $query = $this->baseQuery($centroCosto);
        $this->assertNoMultiplePrices($query);

        return $query->orderBy('referenze_fornitore.descrizione')
            ->orderBy('referenze_fornitore.supplier_code');
    }

    /**
     * @param  Builder<ListinoReferenza>  $query
     * @return Builder<ListinoReferenza>
     */
    public function applySearch(Builder $query, mixed $search): Builder
    {
        $search = is_scalar($search) ? trim((string) $search) : '';

        if ($search === '') {
            return $query;
        }

        return $query->where(function (Builder $query) use ($search): void {
            $query->where('referenze_fornitore.descrizione', 'like', "%{$search}%")
                ->orWhere('referenze_fornitore.descrizione_estesa', 'like', "%{$search}%")
                ->orWhere('referenze_fornitore.supplier_code', 'like', "%{$search}%")
                ->orWhere('referenze_fornitore.customer_article_code', 'like', "%{$search}%");
        });
    }

    /**
     * @param  Builder<ListinoReferenza>  $query
     * @return Builder<ListinoReferenza>
     */
    public function applyCategory(Builder $query, mixed $category): Builder
    {
        $category = is_scalar($category) ? trim((string) $category) : '';

        if ($category === '') {
            return $query;
        }

        if ($category === CategoriaCatalogo::FILTER_WITHOUT_CATEGORY) {
            return $query->whereNotExists(function ($categoryQuery): void {
                $this->constrainVisibleCategory($categoryQuery);
            });
        }

        $categoryId = filter_var($category, FILTER_VALIDATE_INT);
        if ($categoryId === false || $categoryId < 1) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereExists(function ($categoryQuery) use ($categoryId): void {
            $this->constrainVisibleCategory($categoryQuery, $categoryId);
        });
    }

    /**
     * @return array<int, string>
     */
    public function categoryOptions(CentroCosto $centroCosto): array
    {
        return $this->query($centroCosto)
            ->reorder()
            ->join(
                'referenza_fornitore_categoria',
                'referenza_fornitore_categoria.referenza_fornitore_id',
                '=',
                'referenze_fornitore.id'
            )
            ->join(
                'categorie_catalogo',
                'categorie_catalogo.id',
                '=',
                'referenza_fornitore_categoria.categoria_catalogo_id'
            )
            ->where('categorie_catalogo.attiva', true)
            ->where('categorie_catalogo.nome', '<>', '')
            ->whereColumn('categorie_catalogo.fornitore_id', 'fornitori.id')
            ->select(['categorie_catalogo.id', 'categorie_catalogo.nome'])
            ->distinct()
            ->orderBy('categorie_catalogo.nome')
            ->pluck('categorie_catalogo.nome', 'categorie_catalogo.id')
            ->all();
    }

    /**
     * @return Collection<int, CatalogoClienteItem>
     */
    public function items(
        CentroCosto $centroCosto,
        ?string $search = null,
        ?string $category = null,
    ): Collection {
        $query = $this->query($centroCosto);
        $this->applySearch($query, (string) $search);
        $this->applyCategory($query, $category);

        return $query->get()
            ->map(static fn (ListinoReferenza $price): CatalogoClienteItem => CatalogoClienteItem::fromModel($price));
    }

    public function hasUnavailableAssignments(CentroCosto $centroCosto): bool
    {
        $today = now()->toDateString();

        return $centroCosto->listiniCommerciali()
            ->where(function (Builder $query) use ($today): void {
                $query->where('Listini.attivo', false)
                    ->orWhereDate('Listini.valido_dal', '>', $today)
                    ->orWhereDate('Listini.valido_al', '<', $today)
                    ->orWhereHas('fornitore', static fn (Builder $query): Builder => $query->where('attivo', false));
            })
            ->exists();
    }

    /**
     * @return Builder<ListinoReferenza>
     */
    private function baseQuery(CentroCosto $centroCosto): Builder
    {
        $today = now()->toDateString();

        return ListinoReferenza::query()
            ->select('listino_referenze.*')
            ->join('Listini', 'Listini.id', '=', 'listino_referenze.listino_id')
            ->join(
                'centro_costo_listino',
                'centro_costo_listino.listino_id',
                '=',
                'Listini.id'
            )
            ->join('fornitori', 'fornitori.id', '=', 'Listini.fornitore_id')
            ->join(
                'referenze_fornitore',
                'referenze_fornitore.id',
                '=',
                'listino_referenze.referenza_fornitore_id'
            )
            ->where('centro_costo_listino.centro_costo_id', $centroCosto->getKey())
            ->where('Listini.tipo', 'acquisto')
            ->where('Listini.attivo', true)
            ->where('fornitori.attivo', true)
            ->where('listino_referenze.attivo', true)
            ->where('listino_referenze.ordinabile', true)
            ->whereNotNull('listino_referenze.prezzo')
            ->where('listino_referenze.prezzo', '>', 0)
            ->where('referenze_fornitore.attivo', true)
            ->where('referenze_fornitore.ordinabile', true)
            ->whereColumn('referenze_fornitore.fornitore_id', 'Listini.fornitore_id')
            ->where(function (Builder $query) use ($today): void {
                $query->whereNull('Listini.valido_dal')
                    ->orWhereDate('Listini.valido_dal', '<=', $today);
            })
            ->where(function (Builder $query) use ($today): void {
                $query->whereNull('Listini.valido_al')
                    ->orWhereDate('Listini.valido_al', '>=', $today);
            })
            ->with([
                'listino:id,nome_listino,fornitore_id',
                'referenza:id,fornitore_id,supplier_code,customer_article_code,descrizione,descrizione_estesa,categoria,sales_unit,immagine_path',
                'referenza.fornitore:id,code,nome',
                'referenza.categorie' => static fn ($query) => $query
                    ->where('attiva', true)
                    ->where('nome', '<>', '')
                    ->orderBy('nome'),
                'referenza.packagings' => static fn ($query) => $query
                    ->orderBy('livello')
                    ->orderBy('id'),
            ]);
    }

    /**
     * @param  Builder<ListinoReferenza>  $query
     */
    private function assertNoMultiplePrices(Builder $query): void
    {
        $duplicateReferenceIds = (clone $query)
            ->reorder()
            ->withoutEagerLoads()
            ->select('listino_referenze.referenza_fornitore_id')
            ->groupBy('listino_referenze.referenza_fornitore_id')
            ->havingRaw('COUNT(DISTINCT listino_referenze.id) > 1')
            ->pluck('listino_referenze.referenza_fornitore_id');

        if ($duplicateReferenceIds->isEmpty()) {
            return;
        }

        $codes = DB::table('referenze_fornitore')
            ->whereIn('id', $duplicateReferenceIds)
            ->orderBy('supplier_code')
            ->pluck('supplier_code')
            ->all();

        throw new CatalogoClienteIncoerenteException(
            'Il catalogo contiene piu prezzi validi per la stessa referenza.',
            $codes,
        );
    }

    private function constrainVisibleCategory(QueryBuilder $query, ?int $categoryId = null): void
    {
        $query->selectRaw('1')
            ->from('referenza_fornitore_categoria')
            ->join(
                'categorie_catalogo',
                'categorie_catalogo.id',
                '=',
                'referenza_fornitore_categoria.categoria_catalogo_id'
            )
            ->whereColumn(
                'referenza_fornitore_categoria.referenza_fornitore_id',
                'referenze_fornitore.id'
            )
            ->where('categorie_catalogo.attiva', true)
            ->where('categorie_catalogo.nome', '<>', '')
            ->whereColumn('categorie_catalogo.fornitore_id', 'fornitori.id')
            ->when(
                $categoryId !== null,
                static fn ($query) => $query->where('categorie_catalogo.id', $categoryId),
            );
    }
}
