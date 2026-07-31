<?php

declare(strict_types=1);

namespace App\Filament\Resources\Listinos\RelationManagers;

use App\Models\CategoriaCatalogo;
use App\Models\Listino;
use App\Models\ListinoReferenza;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
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
                'referenza.categorie' => static fn ($query) => $query
                    ->where('attiva', true)
                    ->where('nome', '<>', '')
                    ->with('fornitore:id,code')
                    ->orderBy('nome'),
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
                TextColumn::make('catalog_categories')
                    ->label('Categoria')
                    ->state(fn (ListinoReferenza $record): array => $record->referenza
                        ->categorie
                        ->map(static fn (CategoriaCatalogo $category): string => sprintf(
                            '%s / %s',
                            $category->fornitore->code,
                            $category->nome,
                        ))
                        ->all())
                    ->badge()
                    ->placeholder('Senza categoria')
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
            ->filters([
                SelectFilter::make('categoria_catalogo')
                    ->label('Categoria')
                    ->options(fn (): array => $this->categoryOptions())
                    ->placeholder('Tutte le categorie')
                    ->query(fn (Builder $query, array $data): Builder => $this->applyCategoryFilter(
                        $query,
                        $data['value'] ?? null,
                    )),
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

    /**
     * @return array<int|string, string>
     */
    private function categoryOptions(): array
    {
        $listino = $this->getOwnerRecord();

        if (! $listino instanceof Listino || $listino->fornitore_id === null) {
            return [];
        }

        $categories = CategoriaCatalogo::query()
            ->where('fornitore_id', $listino->fornitore_id)
            ->where('attiva', true)
            ->where('nome', '<>', '')
            ->whereHas(
                'referenze.prezziListino',
                static fn (Builder $query): Builder => $query->where('listino_id', $listino->getKey()),
            )
            ->with('fornitore:id,code')
            ->withCount([
                'referenze as listino_references_count' => static fn (Builder $query): Builder => $query
                    ->whereHas(
                        'prezziListino',
                        static fn (Builder $query): Builder => $query->where('listino_id', $listino->getKey()),
                    ),
            ])
            ->orderBy('nome')
            ->get()
            ->mapWithKeys(static fn (CategoriaCatalogo $category): array => [
                $category->getKey() => sprintf(
                    '%s / %s (%d)',
                    $category->fornitore->code,
                    $category->nome,
                    $category->listino_references_count,
                ),
            ])
            ->all();

        return [
            CategoriaCatalogo::FILTER_WITHOUT_CATEGORY => 'Senza categoria',
        ] + $categories;
    }

    private function applyCategoryFilter(Builder $query, mixed $value): Builder
    {
        $value = is_scalar($value) ? trim((string) $value) : '';
        $listino = $this->getOwnerRecord();

        if ($value === '' || ! $listino instanceof Listino || $listino->fornitore_id === null) {
            return $query;
        }

        $categoryConstraint = static fn (Builder $query): Builder => $query
            ->where('attiva', true)
            ->where('nome', '<>', '')
            ->where('fornitore_id', $listino->fornitore_id);

        if ($value === CategoriaCatalogo::FILTER_WITHOUT_CATEGORY) {
            return $query->whereDoesntHave('referenza.categorie', $categoryConstraint);
        }

        $categoryId = filter_var($value, FILTER_VALIDATE_INT);
        if ($categoryId === false || $categoryId < 1) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas(
            'referenza.categorie',
            static fn (Builder $query): Builder => $categoryConstraint($query)
                ->whereKey($categoryId),
        );
    }
}
