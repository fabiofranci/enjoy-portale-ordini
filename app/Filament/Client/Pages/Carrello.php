<?php

namespace App\Filament\Client\Pages;

use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Session;
use BackedEnum;
use App\Models\Ordine;
use App\Models\OrdineItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class Carrello extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;
    protected static ?string $navigationLabel = 'Carrello';
    protected static ?string $title = 'Il tuo carrello';
    protected static ?string $slug = 'carrello';

    protected string $view = 'filament.client.carrello.index';

    public array $cart = [];
    public float $totale = 0;

    public function mount(): void
    {
        $this->loadCart();
    }

    protected function loadCart(): void
    {
        $this->cart = Session::get('cart', []);
        $this->totale = collect($this->cart)->sum(
            fn ($item) => $item['prezzo_unitario'] * $item['quantita']
        );
    }

    protected function sync(): void
    {
        Session::put('cart', $this->cart);
        $this->loadCart();
    }

    public function increment(string $key): void
    {
        $this->cart[$key]['quantita']++;
        $this->sync();
    }

    public function decrement(string $key): void
    {
        $this->cart[$key]['quantita']--;

        if ($this->cart[$key]['quantita'] <= 0) {
            unset($this->cart[$key]);
        }

        $this->sync();
    }

    public function remove(string $key): void
    {
        unset($this->cart[$key]);
        $this->sync();
    }

    public function clear(): void
    {
        $this->cart = [];
        Session::forget('cart');
        $this->totale = 0;
    }

    // 🔹 badge nel menu (rimane perfetto così)
    public static function getNavigationBadge(): ?string
    {
        $cart = Session::get('cart', []);
        return count($cart) > 0 ? (string) count($cart) : null;
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return 'primary';
    }

public function proceed(): void
{
    if (empty($this->cart)) {
        return;
    }

    DB::transaction(function () {

        // 🔹 1. crea ordine (testata)
        $ordine = Ordine::create([
            'user_id'             => Auth::id(),
            'centro_costo_id'     => Auth::user()->centro_costo_id ?? null,
            'stato'               => 'inviato',
            'riferimento_cliente' => null,
            'note'                => null,
            'extra_budget'        => false,
            'totale_lordo'        => 0,
            'totale_netto'        => 0,
            'iva_totale'          => 0,
        ]);

        // 🔹 2. crea righe ordine
        foreach ($this->cart as $item) {

            $prezzoLordo = $item['prezzo_unitario']
                ?? $item['prezzo_unitario_lordo']
                ?? 0;

            $riga = new OrdineItem([
                'prodotto_id'           => $item['prodotto_id'] ?? null,
                'quantita'              => $item['quantita'],
                'prezzo_unitario_lordo' => $prezzoLordo,
                'sconto_percentuale'    => $item['sconto_percentuale'] ?? 0,
                'iva_percentuale'       => $item['iva_percentuale'] ?? 22,
            ]);

            $riga->ordine_id = $ordine->id;
            $riga->calcolaTotali();
            $riga->save();
        }

        // 🔹 3. ricalcola totali ordine
        $ordine->load('items');
        $ordine->ricalcolaTotali();

        // 🔹 4. svuota carrello
        session()->forget('cart');
    });

    // reset stato Livewire
    $this->cart = [];
    $this->totale = 0;
}

}
