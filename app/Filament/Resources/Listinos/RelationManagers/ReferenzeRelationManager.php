<?php

declare(strict_types=1);

namespace App\Filament\Resources\Listinos\RelationManagers;

use App\Models\ListinoReferenza;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ReferenzeRelationManager extends RelationManager
{
    protected static string $relationship = 'referenzeListino';

    protected static ?string $title = 'Referenze fornitore';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'referenza.packagings',
            ]))
            ->columns([
                ImageColumn::make('referenza.immagine_path')
                    ->label('Immagine')
                    ->disk('public')
                    ->square(),
                TextColumn::make('referenza.supplier_code')
                    ->label('Codice fornitore')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('referenza.customer_article_code')
                    ->label('Codice cliente')
                    ->searchable(),
                TextColumn::make('referenza.descrizione')
                    ->label('Descrizione')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('prezzo')
                    ->label('Prezzo')
                    ->money('EUR')
                    ->sortable(),
                TextColumn::make('price_unit')
                    ->label('UM prezzo'),
                TextColumn::make('packaging')
                    ->label('Packaging')
                    ->state(fn (ListinoReferenza $record): string => $record->referenza
                        ->packagings
                        ->map(fn ($packaging): string => sprintf(
                            '1 %s = %s %s',
                            $packaging->unita_contenitore,
                            rtrim(rtrim((string) $packaging->quantita, '0'), '.'),
                            $packaging->unita_contenuta
                        ))
                        ->implode(', '))
                    ->wrap(),
                IconColumn::make('ordinabile')
                    ->label('Ordinabile')
                    ->boolean(),
                IconColumn::make('modificato_manualmente')
                    ->label('Manuale')
                    ->boolean(),
            ])
            ->recordActions([
                EditAction::make()
                    ->label('Modifica prezzo')
                    ->schema([
                        TextInput::make('prezzo')
                            ->label('Prezzo')
                            ->numeric()
                            ->minValue(0)
                            ->required(),
                    ])
                    ->using(function (array $data, ListinoReferenza $record): void {
                        $record->update([
                            'prezzo' => $data['prezzo'],
                            'modificato_manualmente' => true,
                        ]);
                    }),
            ]);
    }
}
