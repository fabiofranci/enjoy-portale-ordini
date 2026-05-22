<?php

namespace App\Filament\Client\Resources\Prodotti\Pages;

use App\Filament\Client\Resources\Prodotti\ProdottoResource;
use Filament\Resources\Pages\ViewRecord;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Notifications\Notification;
use App\Models\Listino;

class ViewProdotto extends ViewRecord
{
    protected static string $resource = ProdottoResource::class;
    protected string $view = 'filament.client.prodotti.view-prodotto';

    protected static ?string $title = 'Dettaglio prodotto';

    public string $selectedUnit;
    public float $prezzoBase;

    public function mount($record): void
    {
        parent::mount($record);

        $this->record->load([
            'packagings',
            'listini',   // ⬅️ FONDAMENTALE
        ]);
    }

    public function getViewData(): array
    {
        $prodotto = $this->record;

        $prezzoBase = $prodotto->getPrezzoAttivo();

        return [
            'record' => $prodotto,
            'prezzoBase' => $prezzoBase !== null ? (float) $prezzoBase : null,
        ];
    }


    public function getHeaderActions(): array
    {
        return [
            Action::make('addToCart')
                ->label('Aggiungi al carrello')
                ->icon('heroicon-o-shopping-cart')
                ->color('primary')
                ->action(function () {
                    $cart = session()->get('cart', []);
                    $prodotto = $this->record;

                    $prezzo = $prodotto->getPrezzoAttivo();

                    if ($prezzo === null) {
                        Notification::make()
                            ->title('Prezzo non disponibile')
                            ->danger()
                            ->send();

                        return;
                    }

                    $cart[$prodotto->id] = [
                        'id' => $prodotto->id,
                        'nome' => $prodotto->nome,
                        'prezzo_unitario' => $prezzo,
                        'quantita' => ($cart[$prodotto->id]['quantita'] ?? 0) + 1,
                    ];

                    session()->put('cart', $cart);

                    Notification::make()
                        ->title("{$prodotto->nome} aggiunto al carrello")
                        ->success()
                        ->send();
                })
        ];
    }

}

