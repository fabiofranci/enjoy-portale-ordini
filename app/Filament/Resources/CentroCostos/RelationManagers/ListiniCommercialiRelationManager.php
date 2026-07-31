<?php

declare(strict_types=1);

namespace App\Filament\Resources\CentroCostos\RelationManagers;

use App\Models\Listino;
use App\Services\Catalog\CentroCostoListinoService;
use App\Services\Catalog\Exceptions\ListinoAssignmentException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class ListiniCommercialiRelationManager extends RelationManager
{
    protected static string $relationship = 'listiniCommerciali';

    protected static ?string $title = 'Listini commerciali';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(
                fn (Builder $query): Builder => $query
                    ->with('fornitore')
                    ->withCount('referenzeOrdinabili')
            )
            ->columns([
                TextColumn::make('nome_listino')
                    ->label('Listino')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('fornitore.code')
                    ->label('Fornitore')
                    ->formatStateUsing(
                        fn (?string $state, Listino $record): string => trim(
                            ($state ?? '').' - '.($record->fornitore?->nome ?? '')
                        )
                    ),
                TextColumn::make('stato_assegnazione')
                    ->label('Stato')
                    ->state(fn (Listino $record): string => self::assignmentStatus($record))
                    ->badge()
                    ->color(
                        fn (Listino $record): string => $record->attivo
                            && $record->fornitore?->attivo
                                ? 'success'
                                : 'warning'
                    ),
                TextColumn::make('referenze_ordinabili_count')
                    ->label('Referenze attive')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('pivot.created_at')
                    ->label('Assegnato il')
                    ->dateTime('d/m/Y H:i'),
            ])
            ->headerActions([
                Action::make('assegnaListini')
                    ->label('Assegna listini')
                    ->icon(Heroicon::OutlinedPlus)
                    ->schema([
                        Select::make('listino_ids')
                            ->label('Listini')
                            ->options(fn (): array => $this->availableListinoOptions())
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->required(),
                    ])
                    ->action(function (array $data, Schema $schema): void {
                        try {
                            app(CentroCostoListinoService::class)->assign(
                                $this->getOwnerRecord(),
                                $data['listino_ids']
                            );
                        } catch (ListinoAssignmentException $exception) {
                            throw ValidationException::withMessages([
                                "{$schema->getStatePath()}.listino_ids" => $exception->getMessage(),
                            ]);
                        }
                    }),
            ])
            ->recordActions([
                Action::make('rimuoviAssegnazione')
                    ->label('Rimuovi')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (Listino $record): void {
                        try {
                            app(CentroCostoListinoService::class)->remove(
                                $this->getOwnerRecord(),
                                $record
                            );
                        } catch (ListinoAssignmentException $exception) {
                            throw ValidationException::withMessages([
                                'listino' => $exception->getMessage(),
                            ]);
                        }
                    }),
            ])
            ->emptyStateHeading('Nessun listino commerciale assegnato');
    }

    /**
     * @return array<int, string>
     */
    private function availableListinoOptions(): array
    {
        return app(CentroCostoListinoService::class)
            ->availableListini($this->getOwnerRecord())
            ->mapWithKeys(static fn (Listino $listino): array => [
                $listino->getKey() => sprintf(
                    '%s - %s',
                    $listino->fornitore->code,
                    $listino->nome_listino
                ),
            ])
            ->all();
    }

    public static function assignmentStatus(Listino $listino): string
    {
        if (! $listino->attivo) {
            return 'Listino inattivo - da correggere';
        }

        if (! $listino->fornitore?->attivo) {
            return 'Fornitore inattivo - da correggere';
        }

        return 'Attivo';
    }
}
