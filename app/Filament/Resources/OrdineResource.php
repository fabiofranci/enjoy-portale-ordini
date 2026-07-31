<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OrdineResource\Pages;
use App\Filament\Resources\OrdineResource\RelationManagers\ItemsRelationManager;
use App\Models\Ordine;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class OrdineResource extends Resource
{
    protected static ?string $model = Ordine::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static ?string $navigationLabel = 'Ordini';

    protected static ?int $navigationSort = 60;

    protected static ?string $modelLabel = 'Ordine';

    protected static ?string $pluralModelLabel = 'Ordini';

    protected static ?string $slug = 'ordini';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->sortable(),

                TextColumn::make('cliente_nome')
                    ->label('Cliente')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('centro_costo_nome')
                    ->label('Centro di costo')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('fornitore_code')
                    ->label('Fornitore')
                    ->sortable(),

                BadgeColumn::make('stato')
                    ->colors([
                        'gray' => 'bozza',
                        'primary' => 'inviato',
                        'warning' => 'in_attesa_approvazione',
                        'danger' => 'rifiutato',
                        'success' => 'approvato',
                    ]),

                BadgeColumn::make('email_stato')
                    ->label('Email')
                    ->colors([
                        'gray' => 'in_attesa',
                        'success' => 'inviata',
                        'warning' => 'parziale',
                        'danger' => 'errore',
                    ]),

                TextColumn::make('totale_lordo')
                    ->label('Totale')
                    ->money('EUR')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Creato il')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('stato')
                    ->options([
                        'bozza' => 'Bozza',
                        'inviato' => 'Inviato',
                        'in_attesa_approvazione' => 'In attesa approvazione',
                        'rifiutato' => 'Rifiutato',
                        'approvato' => 'Approvato',
                    ]),
            ])
            ->actions([
                Action::make('view')
                    ->label('Vedi')
                    ->icon('heroicon-o-eye')
                    ->url(fn (Ordine $record) => static::getUrl('view', ['record' => $record])),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            ItemsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrdini::route('/'),
            'view' => Pages\ViewOrdine::route('/{record}'),
        ];
    }
}
