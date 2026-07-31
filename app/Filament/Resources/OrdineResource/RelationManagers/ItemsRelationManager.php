<?php

namespace App\Filament\Resources\OrdineResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Righe ordine';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('supplier_code')
                    ->label('Codice'),

                TextColumn::make('descrizione')
                    ->label('Articolo')
                    ->wrap(),

                TextColumn::make('quantita')
                    ->label('Q.tà')
                    ->alignCenter(),

                TextColumn::make('unita')
                    ->label('UDM')
                    ->alignCenter(),

                TextColumn::make('prezzo_unitario_lordo')
                    ->label('Prezzo IVA incl.')
                    ->money('EUR'),

                TextColumn::make('totale_riga_lordo')
                    ->label('Totale IVA incl.')
                    ->money('EUR')
                    ->weight('bold'),
            ])
            ->paginated(false)
            ->recordActions([])   // read-only
            ->headerActions([]);  // read-only
    }
}
