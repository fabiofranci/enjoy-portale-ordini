<?php

namespace App\Filament\Resources\Fornitori;

use App\Filament\Resources\Fornitori\Pages\CreateFornitore;
use App\Filament\Resources\Fornitori\Pages\EditFornitore;
use App\Filament\Resources\Fornitori\Pages\ListFornitori;
use App\Filament\Resources\Fornitori\Schemas\FornitoreForm;
use App\Filament\Resources\Fornitori\Tables\FornitoriTable;
use App\Models\Fornitore;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class FornitoreResource extends Resource
{

    protected static ?string $model = Fornitore::class;

    protected static ?string $navigationLabel = 'Fornitori';

    protected static ?int $navigationSort = 20;

    protected static ?string $pluralModelLabel = 'Fornitori';

    protected static ?string $modelLabel = 'Fornitore';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;


    public static function form(Schema $schema): Schema
    {
        return FornitoreForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FornitoriTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFornitori::route('/'),
            'create' => CreateFornitore::route('/create'),
            'edit' => EditFornitore::route('/{record}/edit'),
        ];
    }

    public static function getNavigationLabel(): string
    {
        return ucfirst(__('Fornitori'));
    }

    public static function getBreadcrumb(): string
    {
        return ucfirst(__('Fornitori'));
    }

    public static function getModelLabel(): string
    {
        return ucfirst(__('Fornitore'));
    }

    public static function getPluralModelLabel(): string
    {
        return ucfirst(__('Fornitori'));
    }
}
