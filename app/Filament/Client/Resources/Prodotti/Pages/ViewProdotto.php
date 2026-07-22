<?php

namespace App\Filament\Client\Resources\Prodotti\Pages;

use App\Filament\Client\Resources\Prodotti\ProdottoResource;
use Filament\Resources\Pages\ViewRecord;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Notifications\Notification;
use App\Models\Listino;
use App\Models\User;
use App\Services\PrezziService;

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
        $user = auth()->user();
        $pricing = PrezziService::prezzoVisibile($prodotto, $user instanceof User ? $user : null);
        $prezzoBase = ($pricing['ordinabile'] ?? false) === true ? ($pricing['prezzo'] ?? null) : null;

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
                    $user = auth()->user();
                    $pricing = PrezziService::prezzoVisibile($prodotto, $user instanceof User ? $user : null);

                    if (($pricing['ordinabile'] ?? false) !== true || ($pricing['prezzo'] ?? null) === null) {
                        Notification::make()
                            ->title('Prodotto non ordinabile')
                            ->danger()
                            ->send();

                        return;
                    }

                    $cart[$prodotto->id] = [
                        'id' => $prodotto->id,
                        'prodotto_id' => $prodotto->id,
                        'nome' => $prodotto->nome,
                        'unita' => $prodotto->unita_misura ?? 'NR',
                        'prezzo_unitario' => (float) ($pricing['prezzo_lordo'] ?? $pricing['prezzo']),
                        'sconto_percentuale' => (float) ($pricing['sconto_percentuale'] ?? 0),
                        'iva_percentuale' => (float) ($pricing['iva_percentuale'] ?? 22),
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

