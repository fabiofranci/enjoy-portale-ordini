<?php

namespace App\Filament\Client\Resources\Prodotti;

use App\Filament\Client\Resources\Prodotti\Pages\ListProdotti;
use App\Filament\Client\Resources\Prodotti\Tables\ProdottiTable;
use App\Models\Product;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Filament\Actions\Action;

class ProdottoResource extends Resource
{
    protected static ?string $model = Product::class;

    // Label & plural in ITA
    protected static ?string $modelLabel = 'Prodotto';
    protected static ?string $pluralModelLabel = 'Prodotti';
    protected static ?string $navigationLabel = 'Catalogo Prodotti';
    protected static ?string $slug = 'prodotti';

    // Icona (Filament v4)
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    public static function table(Table $table): Table
    {
        return ProdottiTable::configure($table);
    }

    public static function getHeaderActions(): array
    {
        return [
            Action::make('carrello')
                ->label('Vai al carrello')
                ->icon('heroicon-o-shopping-cart')
                ->url(route('clienti.carrello.show')),
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProdotti::route('/'),
            'view'  => Pages\ViewProdotto::route('/{record}'),
        ];
    }
}
