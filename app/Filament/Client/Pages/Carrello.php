<?php

namespace App\Filament\Client\Pages;

use App\Models\User;
use App\Services\Orders\OrderSubmissionService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Throwable;

class Carrello extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;
    protected static ?string $navigationLabel = 'Carrello';
    protected static ?string $title = 'Il tuo carrello';
    protected static ?string $slug = 'carrello';

    protected string $view = 'filament.client.carrello.index';

    public array $cart = [];
    public float $totale = 0;
    public string $confirmationNumber = '';

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
        $this->confirmationNumber = '';
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
            Notification::make()
                ->title('Il carrello e\' vuoto')
                ->warning()
                ->send();

            return;
        }

        $this->validate([
            'confirmationNumber' => ['required', 'regex:/^[0-9]+$/', 'max:50'],
        ], [
            'confirmationNumber.required' => 'Inserisci il numero di conferma ordine.',
            'confirmationNumber.regex' => 'Il numero di conferma ordine deve contenere solo cifre.',
            'confirmationNumber.max' => 'Il numero di conferma ordine e\' troppo lungo.',
        ]);

        $user = Auth::user();

        if (!$user instanceof User) {
            Notification::make()
                ->title('Sessione non valida')
                ->danger()
                ->send();

            return;
        }

        try {
            $ordine = app(OrderSubmissionService::class)->submit($user, $this->cart, $this->confirmationNumber);

            session()->forget('cart');
            $this->cart = [];
            $this->totale = 0;
            $this->confirmationNumber = '';

            Notification::make()
                ->title('Ordine inviato')
                ->body(sprintf(
                    'Ordine #%d inviato a iGroup e registrato su Odoo come richiesta di preventivo.',
                    $ordine->id
                ))
                ->success()
                ->send();
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())
                ->flatten()
                ->first() ?? 'I dati inseriti non sono validi.';

            Notification::make()
                ->title('Invio non completato')
                ->body((string) $message)
                ->danger()
                ->send();
        } catch (Throwable $exception) {
            Log::error('Cart order submission failed', [
                'user_id' => $user->id,
                'confirmation_number' => $this->confirmationNumber,
                'exception' => $exception,
            ]);

            Notification::make()
                ->title('Invio non completato')
                ->body('L\'ordine e\' stato salvato solo parzialmente o richiede un nuovo tentativo. Riprova con lo stesso numero di conferma oppure contatta l\'assistenza.')
                ->danger()
                ->persistent()
                ->send();
        }
    }

}
