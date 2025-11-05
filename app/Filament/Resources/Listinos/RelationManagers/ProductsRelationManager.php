<?php

namespace App\Filament\Resources\Listinos\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class ProductsRelationManager extends RelationManager
{
    protected static string $relationship = 'products';
    protected static ?string $title = 'Prodotti nel listino';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('nome')
            ->columns([
                Tables\Columns\TextColumn::make('nome')
                    ->label('Prodotto')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('codice')
                    ->label('Codice')
                    ->sortable(),

                Tables\Columns\TextColumn::make('pivot.prezzo_lordo')
                    ->label('Prezzo Lordo (€)')
                    ->numeric(2),

                Tables\Columns\TextColumn::make('pivot.sconto_percentuale')
                    ->label('Sconto %')
                    ->numeric(2),

                Tables\Columns\TextColumn::make('pivot.prezzo')
                    ->label('Prezzo Netto (€)')
                    ->numeric(2),

                Tables\Columns\TextColumn::make('pivot.iva_percentuale')
                    ->label('IVA %')
                    ->numeric(2),
            ])
            ->paginated(false) // facoltativo
            ->emptyStateHeading('Nessun prodotto associato');
    }
}
