<?php

namespace App\Filament\Resources\ProductResource\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Validation\Rule;

class ProductPackagingRelationManager extends RelationManager
{
    protected static string $relationship = 'packagings';

    protected static ?string $title = 'Packaging';

    public function form(Schema $schema): Schema
    {
        return $schema->schema([
            Select::make('from_unit')
                ->label('Unità confezione')
                ->options([
                    'NR' => 'NR',
                    'CF' => 'CF',
                    'CT' => 'CT',
                ])
                ->required()
                ->rule(fn ($get) => Rule::notIn([$get('to_unit')])),

            TextInput::make('multiplier')
                ->label('Quantità')
                ->numeric()
                ->minValue(0.0001)
                ->required()
                ->helperText('Quante unità contiene una confezione'),

            Select::make('to_unit')
                ->label('Unità contenuta')
                ->options([
                    'NR' => 'NR',
                    'CF' => 'CF',
                    'CT' => 'CT',
                ])
                ->required()
                ->rule(fn ($get) => Rule::notIn([$get('from_unit')])),
        ])->columns(3);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('from_unit')->label('Confezione'),
                Tables\Columns\TextColumn::make('multiplier')->label('Quantità'),
                Tables\Columns\TextColumn::make('to_unit')->label('Unità'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Aggiungi packaging')
                    ->visible(fn () => $this->getOwnerRecord()->packagings()->count() < 2),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->emptyStateHeading('Nessun packaging definito')
            ->emptyStateDescription('Definisci il rapporto tra unità di vendita e confezioni.');
    }
}
