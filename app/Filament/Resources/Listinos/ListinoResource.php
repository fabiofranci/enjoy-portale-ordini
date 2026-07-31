<?php

namespace App\Filament\Resources\Listinos;

use App\Filament\Resources\Listinos\Pages\CreateListino;
use App\Filament\Resources\Listinos\Pages\EditListino;
use App\Filament\Resources\Listinos\Pages\ListListinos;
use App\Filament\Resources\Listinos\Pages\ViewListino;
use App\Filament\Resources\Listinos\RelationManagers\ListinoItemsRelationManager;
use App\Filament\Resources\Listinos\RelationManagers\ProductsRelationManager;
use App\Filament\Resources\Listinos\RelationManagers\ReferenzeRelationManager;
use App\Filament\Resources\Listinos\Schemas\ListinoForm;
use App\Filament\Resources\Listinos\Schemas\ListinoInfolist;
use App\Filament\Resources\Listinos\Tables\ListinosTable;
use App\Models\Listino;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class ListinoResource extends Resource
{
    protected static ?string $model = Listino::class;

    protected static ?string $navigationLabel = 'Listini';

    protected static ?int $navigationSort = 50;

    protected static ?string $modelLabel = 'Listino';

    protected static ?string $pluralModelLabel = 'Listini';

    public static function form(Schema $schema): Schema
    {
        return $schema->schema(ListinoForm::make());
    }

    public static function table(Table $table): Table
    {
        return $table->columns(ListinosTable::schema());
    }

    public static function infolist(Schema $schema): Schema
    {
        return ListinoInfolist::configure($schema);
    }

    public static function getRelations(): array
    {
        return [
            ReferenzeRelationManager::class,
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
