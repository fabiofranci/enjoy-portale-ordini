<?php

namespace App\Filament\Client\Pages;

use App\Models\OrdineItem;
use App\Services\CartService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Actions\Action;

class Carrello extends Page implements Tables\Contracts\HasTable
{
    use Tables\Concerns\InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';
    protected static ?string $navigationLabel = 'Carrello';
    protected static string $view = 'filament.client.pages.carrello';
    protected static ?string $slug = 'carrello';

    public function getHeading(): string
    {
        $ordine = CartService::getDraftFor(auth()->user());
        return "Carrello – Totale: " . number_format($ordine->totale_lordo, 2, ',', '.') . " €";
    }

    public function table(Table $table): Table
    {
        $ordine = CartService::getDraftFor(auth()->user());

        return $table
            ->query(fn () => $ordine->items()->getQuery()->with('product'))
            ->columns([
                Tables\Columns\TextColumn::make('product.nome')->label('Prodotto')->searchable(),
                Tables\Columns\TextColumn::make('prezzo_unitario_lordo')->label('Prezzo')->money('eur', true),
                Tables\Columns\TextColumn::make('sconto_percentuale')->label('Sconto %'),
                Tables\Columns\TextColumn::make('iva_percentuale')->label('IVA %'),
                Tables\Columns\TextColumn::make('quantita')->label('Q.tà'),
                Tables\Columns\TextColumn::make('totale_riga_lordo')->label('Totale')->money('eur', true),
            ])
            ->actions([
                Action::make('qty')
                    ->label('Modifica Q.tà')
                    ->icon('heroicon-o-pencil-square')
                    ->form([
                        Forms\Components\TextInput::make('quantita')->numeric()->minValue(1)->required(),
                    ])
                    ->mountUsing(fn (OrdineItem $record, Forms\Form $form) => $form->fill(['quantita' => $record->quantita]))
                    ->action(function (OrdineItem $record, array $data) {
                        CartService::updateQty(auth()->user(), $record->product_id, (int) $data['quantita']);
                        Notification::make()->success()->title('Quantità aggiornata')->send();
                        $this->dispatch('$refresh');
                    }),
                Action::make('remove')
                    ->label('Rimuovi')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (OrdineItem $record) {
                        CartService::removeProduct(auth()->user(), $record->product_id);
                        Notification::make()->success()->title('Riga rimossa')->send();
                        $this->dispatch('$refresh');
                    }),
            ])
            ->headerActions([
                Action::make('svuota')
                    ->label('Svuota carrello')
                    ->icon('heroicon-o-x-circle')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->action(function () {
                        CartService::empty(auth()->user());
                        Notification::make()->title('Carrello svuotato')->success()->send();
                        $this->dispatch('$refresh');
                    }),
                Action::make('checkout')
                    ->label('Procedi all\'invio')
                    ->icon('heroicon-o-paper-airplane')
                    ->url(fn () => static::getUrl('checkout')), // vedi pagina Checkout qui sotto
            ])
            ->paginated(false);
    }
}
