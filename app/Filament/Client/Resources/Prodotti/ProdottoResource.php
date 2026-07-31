<?php

namespace App\Filament\Client\Resources\Prodotti;

use App\Filament\Client\Resources\Prodotti\Tables\ProdottiTable;
use App\Models\ListinoReferenza;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProdottoResource extends Resource
{
    protected static ?string $model = ListinoReferenza::class;

    protected static ?string $modelLabel = 'Articolo';

    protected static ?string $pluralModelLabel = 'Articoli';

    protected static ?string $navigationLabel = 'Catalogo';

    protected static ?string $slug = 'prodotti';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    public static function table(Table $table): Table
    {
        return ProdottiTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        // Every customer query must pass through CatalogoClienteService.
        return parent::getEloquentQuery()->whereRaw('1 = 0');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProdotti::route('/'),
        ];
    }
}
