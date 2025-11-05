<?php

namespace App\Filament\Client\Pages;

use App\Models\Product;
use App\Services\CartService;
use App\Services\PrezziService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Actions\Action;
use Filament\Tables\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;

class CatalogoProdotti extends Page implements Tables\Contracts\HasTable
{
    use Tables\Concerns\InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-bag';
    protected static ?string $navigationLabel = 'Catalogo';
    protected static string $view = 'filament.client.pages.catalogo-prodotti';
    protected static ?string $slug = 'catalogo';

    public static function shouldRegisterNavigation(): bool
    {
        return true; // nel client panel mostriamo voce semplice
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Product::query()->with('media')) // adatta alle tue relazioni
            ->columns([
                Tables\Columns\ImageColumn::make('immagine')->getStateUsing(
                    fn (Product $record) => $record->immagine_url ?? null
                )->circular()->label(''),
                Tables\Columns\TextColumn::make('nome')->searchable()->toggleable()->label('Prodotto'),
                Tables\Columns\TextColumn::make('categoria.nome')->label('Categoria')->toggleable(),
                Tables\Columns\TextColumn::make('prezzo')
                    ->label('Prezzo')
                    ->getStateUsing(function (Product $record): string {
                        $p = PrezziService::prezzoVisibile($record, auth()->user());
                        return number_format($p['prezzo_lordo'] * (1 - $p['sconto_percentuale']/100), 2, ',', '.') . ' €';
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('categoria_id')
                    ->label('Categoria')
                    ->relationship('categoria', 'nome'),
                Tables\Filters\Filter::make('disponibile')
                    ->label('Disponibili')
                    ->query(fn (Builder $q) => $q->where('disponibile', true)),
            ])
            ->actions([
                Action::make('aggiungi')
                    ->label('Aggiungi')
                    ->icon('heroicon-o-plus-circle')
                    ->form([
                        Forms\Components\TextInput::make('quantita')
                            ->numeric()->default(1)->minValue(1)->required()
                            ->label('Q.tà'),
                    ])
                    ->action(function (Product $record, array $data) {
                        CartService::addProduct(auth()->user(), $record->id, (int) $data['quantita']);
                        Notification::make()->title('Aggiunto al carrello')->success()->send();
                    }),
            ])
            ->paginated([12, 24, 48])
            ->defaultPaginationPageOption(24)
            ->emptyStateHeading('Nessun prodotto')
            ->emptyStateDescription('Prova a rimuovere qualche filtro.');
    }
}
