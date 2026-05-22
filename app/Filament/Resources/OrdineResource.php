<?php

namespace App\Filament\Resources;
use Filament\Support\Icons\Heroicon;

use App\Filament\Resources\OrdineResource\Pages;
use App\Models\Ordine;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Filters\SelectFilter;
use BackedEnum;
use Filament\Actions\Action;
use App\Filament\Resources\OrdineResource\RelationManagers\ItemsRelationManager;


class OrdineResource extends Resource
{
    protected static ?string $model = Ordine::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;
    protected static ?string $navigationLabel = 'Ordini';
    protected static ?int $navigationSort = 20;

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

                TextColumn::make('user.name')
                    ->label('Cliente')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('centroCosto.nome')
                    ->label('Centro di costo')
                    ->sortable()
                    ->toggleable(),

                BadgeColumn::make('stato')
                    ->colors([
                        'warning' => 'bozza',
                        'primary' => 'inviato',
                        'danger'  => 'modificato',
                        'success' => 'accettato',
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
                        'bozza'      => 'Bozza',
                        'inviato'    => 'Inviato',
                        'modificato' => 'Modificato',
                        'accettato'  => 'Accettato',
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
            'view'  => Pages\ViewOrdine::route('/{record}'),
        ];
    }
}
