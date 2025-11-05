<?php

namespace App\Filament\Resources\CentroCostos;

use App\Filament\Resources\CentroCostos\Pages\CreateCentroCosto;
use App\Filament\Resources\CentroCostos\Pages\EditCentroCosto;
use App\Filament\Resources\CentroCostos\Pages\ListCentroCostos;
use App\Filament\Resources\CentroCostos\Schemas\CentroCostoForm;
use App\Filament\Resources\CentroCostos\Tables\CentroCostosTable;
use App\Models\CentroCosto;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class CentroCostoResource extends Resource
{
    protected static ?string $model = CentroCosto::class;

    protected static ?string $navigationLabel = 'Centri di costo';

    protected static ?string $pluralModelLabel = 'Centri di costo';

    protected static ?string $modelLabel = 'Centro di costo';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return CentroCostoForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CentroCostosTable::configure($table);
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
            'index' => ListCentroCostos::route('/'),
            'create' => CreateCentroCosto::route('/create'),
            'edit' => EditCentroCosto::route('/{record}/edit'),
        ];
    }
}
