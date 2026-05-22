<?php

namespace App\Filament\Resources\OrdineResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Righe ordine';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('prodotto.nome')
                    ->label('Prodotto')
                    ->wrap(),

                TextColumn::make('quantita')
                    ->label('Q.tà')
                    ->alignCenter(),

                TextColumn::make('unita')
                    ->label('UDM')
                    ->alignCenter(),

                TextColumn::make('prezzo_unitario_lordo')
                    ->label('Prezzo unitario')
                    ->money('EUR'),

                TextColumn::make('totale_riga_netto')
                    ->label('Netto')
                    ->money('EUR'),

                TextColumn::make('totale_riga_iva')
                    ->label('IVA')
                    ->money('EUR'),

                TextColumn::make('totale_riga_lordo')
                    ->label('Totale')
                    ->money('EUR')
                    ->weight('bold'),
            ])
            ->paginated(false)
            ->recordActions([])   // read-only
            ->headerActions([]);  // read-only
    }
}
