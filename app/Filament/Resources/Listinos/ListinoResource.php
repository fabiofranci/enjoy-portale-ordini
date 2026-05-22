<?php

namespace App\Filament\Resources\Listinos;

use App\Models\Listino;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use App\Filament\Resources\Listinos\Schemas\ListinoForm;
use App\Filament\Resources\Listinos\Tables\ListinosTable;
use App\Filament\Resources\Listinos\Pages\CreateListino;
use App\Filament\Resources\Listinos\Pages\EditListino;
use App\Filament\Resources\Listinos\Pages\ListListinos;
use App\Filament\Resources\Listinos\Pages\ViewListino;
use Filament\Schemas\Schema;
use App\Filament\Resources\Listinos\RelationManagers\ListinoItemsRelationManager;
use App\Filament\Resources\Listinos\RelationManagers\ProductsRelationManager;


class ListinoResource extends Resource
{
    protected static ?string $model = Listino::class;

    public static function form(Schema $schema): Schema
    {
        return $schema->schema(ListinoForm::make());
    }

    public static function table(Table $table): Table
    {
        return $table->columns(ListinosTable::schema());
    }

    public static function getRelations(): array
    {
        return [
            ProductsRelationManager::class,
            ListinoItemsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListListinos::route('/'),
            'create' => CreateListino::route('/create'),
            'edit' => EditListino::route('/{record}/edit'),
            'view' => ViewListino::route('/{record}'),
        ];
    }
}
