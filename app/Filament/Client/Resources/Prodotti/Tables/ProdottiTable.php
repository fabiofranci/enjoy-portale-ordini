<?php

namespace App\Filament\Client\Resources\Prodotti\Tables;

use App\Filament\Client\Resources\Prodotti\Pages\ListProdotti;
use App\Models\ListinoReferenza;
use App\Models\ReferenzaPackaging;
use App\Services\Catalog\CatalogoClienteService;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProdottiTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                ViewColumn::make('referenza.immagine_path')
                    ->label('Immagine')
                    ->view('filament.client.prodotti.columns.catalogo-immagine'),
                TextColumn::make('referenza.descrizione')
                    ->label('Descrizione')
                    ->description(fn (ListinoReferenza $record): ?string => $record->referenza->descrizione_estesa)
                    ->wrap(),
                TextColumn::make('referenza.supplier_code')
                    ->label('Codice fornitore')
                    ->copyable(),
                TextColumn::make('referenza.customer_article_code')
                    ->label('Codice cliente')
                    ->placeholder('-'),
                TextColumn::make('referenza.fornitore.code')
                    ->label('Fornitore')
                    ->description(fn (ListinoReferenza $record): ?string => $record->referenza->fornitore->nome),
                TextColumn::make('prezzo')
                    ->label('Prezzo')
                    ->money('EUR')
                    ->description(fn (ListinoReferenza $record): ?string => $record->price_unit),
                TextColumn::make('referenza.sales_unit')
                    ->label('Unita vendita')
                    ->placeholder('-'),
                TextColumn::make('referenza.packagings')
                    ->label('Packaging')
                    ->state(fn (ListinoReferenza $record): array => $record->referenza->packagings
                        ->map(static fn (ReferenzaPackaging $packaging): string => sprintf(
                            '1 %s = %s %s',
                            $packaging->unita_contenitore,
                            (string) (float) $packaging->quantita,
                            $packaging->unita_contenuta,
                        ))
                        ->all())
                    ->listWithLineBreaks()
                    ->bulleted(),
                TextColumn::make('referenza.categoria')
                    ->label('Categoria')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('listino.nome_listino')
                    ->label('Listino')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('categoria')
                    ->label('Categoria')
                    ->options(fn (ListProdotti $livewire): array => $livewire->categoryOptions())
                    ->query(fn (Builder $query, array $data): Builder => app(CatalogoClienteService::class)
                        ->applyCategory($query, $data['value'] ?? null)),
            ])
            ->searchable()
            ->searchUsing(fn (Builder $query, mixed $search): Builder => app(CatalogoClienteService::class)
                ->applySearch($query, $search))
            ->searchPlaceholder('Descrizione o codice')
            ->paginationPageOptions([10, 25, 50])
            ->defaultPaginationPageOption(25)
            ->emptyStateHeading(fn (ListProdotti $livewire): string => $livewire->catalogEmptyHeading())
            ->emptyStateDescription(fn (ListProdotti $livewire): string => $livewire->catalogEmptyDescription())
            ->recordActions([
                Action::make('addToCart')
                    ->label('Aggiungi al carrello')
                    ->icon('heroicon-o-shopping-cart')
                    ->iconButton()
                    ->tooltip('Aggiungi al carrello')
                    ->action(fn (ListinoReferenza $record, ListProdotti $livewire): mixed => $livewire->addToCart($record)),
            ])
            ->bulkActions([]);
    }
}
