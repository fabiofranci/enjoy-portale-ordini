<?php

namespace App\Filament\Resources\Listinos\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class ListinoItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'odooItems';

    protected static ?string $title = 'Regole Odoo';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('nome_regola')
            ->columns([
                Tables\Columns\TextColumn::make('odoo_id')
                    ->label('Odoo ID')
                    ->sortable(),

                Tables\Columns\TextColumn::make('nome_regola')
                    ->label('Regola')
                    ->searchable()
                    ->wrap(),

                Tables\Columns\BadgeColumn::make('applied_on')
                    ->label('Applica a')
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        '3_global' => 'Tutti i prodotti',
                        '2_product_category' => 'Categoria',
                        '1_product' => 'Prodotto',
                        '0_product_variant' => 'Variante',
                        default => $state ?? '-',
                    })
                    ->colors([
                        'gray' => '3_global',
                        'info' => '2_product_category',
                        'success' => '1_product',
                        'warning' => '0_product_variant',
                    ]),

                Tables\Columns\TextColumn::make('prodotto.nome')
                    ->label('Prodotto')
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('categoria.nome')
                    ->label('Categoria')
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\BadgeColumn::make('compute_price')
                    ->label('Calcolo')
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'fixed' => 'Prezzo fisso',
                        'percentage' => 'Sconto',
                        'formula' => 'Formula',
                        default => $state ?? '-',
                    })
                    ->colors([
                        'success' => 'fixed',
                        'info' => 'percentage',
                        'warning' => 'formula',
                    ]),

                Tables\Columns\BadgeColumn::make('base')
                    ->label('Base')
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'list_price' => 'Prezzo vendita',
                        'standard_price' => 'Costo',
                        'pricelist' => 'Altro listino',
                        default => $state ?? '-',
                    })
                    ->colors([
                        'success' => 'list_price',
                        'warning' => 'standard_price',
                        'info' => 'pricelist',
                    ])
                    ->toggleable(),

                Tables\Columns\TextColumn::make('baseListino.nome_listino')
                    ->label('Listino base')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('min_quantity')
                    ->label('Qta min')
                    ->numeric(4)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('fixed_price')
                    ->label('Prezzo fisso')
                    ->numeric(4)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('percent_price')
                    ->label('Percentuale')
                    ->numeric(4)
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('price_discount')
                    ->label('Discount')
                    ->numeric(4)
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('price_surcharge')
                    ->label('Surcharge')
                    ->numeric(4)
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('date_start')
                    ->label('Dal')
                    ->dateTime()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('date_end')
                    ->label('Al')
                    ->dateTime()
                    ->toggleable(),
            ])
            ->defaultSort('odoo_id')
            ->paginated([25, 50, 100])
            ->emptyStateHeading('Nessuna regola Odoo importata');
    }
}
