<?php

namespace App\Filament\Client\Resources\Prodotti\Pages;

use App\Filament\Client\Resources\Prodotti\ProdottoResource;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListProdotti extends ListRecords
{
    protected static string $resource = ProdottoResource::class;

    // ✅ Applica filtro categoria da query string
    public function getTableQuery(): Builder
    {
        $query = parent::getTableQuery();

        $query->with(['listini', 'packagings', 'categoria']);

        if ($categoriaId = request()->query('categoria')) {
            $query->where('categoria_id', $categoriaId);
        }

        return $query;
    }

    // (facoltativo) modifica il titolo se filtrato
    public function getTitle(): string
    {
        if ($categoriaId = request()->query('categoria')) {
            $categoria = \App\Models\Categoria::find($categoriaId);
            return $categoria
                ? 'Catalogo – ' . $categoria->nome
                : 'Catalogo Prodotti';
        }

        return 'Catalogo Prodotti';
    }
}
