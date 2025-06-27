<?php

namespace App\Filament\Resources\Listinos;

use App\Filament\Resources\Listinos\Pages\CreateListino;
use App\Filament\Resources\Listinos\Pages\EditListino;
use App\Filament\Resources\Listinos\Pages\ListListinos;
use App\Filament\Resources\Listinos\Pages\ViewListino;
use App\Filament\Resources\Listinos\Schemas\ListinoForm;
use App\Filament\Resources\Listinos\Schemas\ListinoInfolist;
use App\Filament\Resources\Listinos\Tables\ListinosTable;
use App\Models\Listino;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ListinoResource extends Resource
{
    protected static ?string $model = Listino::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return ListinoForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ListinoInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ListinosTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListListinos::route('/'),
            'create' => CreateListino::route('/create'),
            'view' => ViewListino::route('/{record}'),
            'edit' => EditListino::route('/{record}/edit'),
        ];
    }
}
