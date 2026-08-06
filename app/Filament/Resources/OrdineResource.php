<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OrdineResource\Pages;
use App\Filament\Resources\OrdineResource\RelationManagers\ItemsRelationManager;
use App\Models\Ordine;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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
                    ->formatStateUsing(fn (string $state, Ordine $record): string => $record->statoLabel())
                    ->color(fn (string $state): string => match ($state) {
                        Ordine::STATUS_NEW, 'rifiutato' => 'danger',
                        Ordine::STATUS_FULFILLED, 'approvato' => 'success',
                        'in_attesa_approvazione' => 'warning',
                        'inviato' => 'primary',
                        default => 'gray',
                    }),

                BadgeColumn::make('priorita')
                    ->label('Priorita')
                    ->formatStateUsing(fn (string $state, Ordine $record): string => $record->prioritaLabel())
                    ->color(fn (string $state): string => $state === Ordine::PRIORITY_URGENT ? 'danger' : 'gray'),

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

                TextColumn::make('data_ordine')
                    ->label('Data ordine')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('stato')
                    ->options([
                        'bozza' => 'Bozza',
                        'inviato' => 'Inviato',
                        Ordine::STATUS_NEW => 'Nuovo',
                        Ordine::STATUS_FULFILLED => 'Evaso',
                        'in_attesa_approvazione' => 'In attesa approvazione',
                        'rifiutato' => 'Rifiutato',
                        'approvato' => 'Approvato',
                    ]),
                Filter::make('data_ordine')
                    ->label('Intervallo data ordine')
                    ->schema([
                        DatePicker::make('da')->label('Da'),
                        DatePicker::make('a')->label('A'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(
                            $data['da'] ?? null,
                            fn (Builder $query, string $date): Builder => $query->whereDate('data_ordine', '>=', $date),
                        )
                        ->when(
                            $data['a'] ?? null,
                            fn (Builder $query, string $date): Builder => $query->whereDate('data_ordine', '<=', $date),
                        ))
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];

                        if ($data['da'] ?? null) {
                            $indicators[] = Indicator::make('Dal '.Carbon::parse($data['da'])->format('d/m/Y'))
                                ->removeField('da');
                        }

                        if ($data['a'] ?? null) {
                            $indicators[] = Indicator::make('Al '.Carbon::parse($data['a'])->format('d/m/Y'))
                                ->removeField('a');
                        }

                        return $indicators;
                    }),
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
