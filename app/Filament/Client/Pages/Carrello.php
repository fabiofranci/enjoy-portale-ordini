<?php

declare(strict_types=1);

namespace App\Filament\Client\Pages;

use App\Models\User;
use App\Services\Orders\CatalogCartService;
use App\Services\Orders\OrderSubmissionService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Throwable;

class Carrello extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    protected static ?string $navigationLabel = 'Carrello';

    protected static ?string $title = 'Il tuo carrello';

    protected static ?string $slug = 'carrello';

    protected static ?int $navigationSort = 20;

    protected string $view = 'filament.client.carrello.index';

    /** @var array<string, array<string, int|float|string|null>> */
    public array $cart = [];

    public float $totale = 0;

    public ?string $centroCostoNome = null;

    public ?string $fornitoreCode = null;

    public string $confirmationNumber = '';

    public string $notes = '';

    public function mount(): void
    {
        $this->loadCart();
    }

    public function increment(string $key): void
    {
        $item = $this->cart[$key] ?? null;

        if ($item !== null) {
            $this->cartService()->updateQuantity($this->user(), $key, (int) $item['quantita'] + 1);
        }

        $this->loadCart();
    }

    public function decrement(string $key): void
    {
        $item = $this->cart[$key] ?? null;

        if ($item !== null) {
            $this->cartService()->updateQuantity($this->user(), $key, (int) $item['quantita'] - 1);
        }

        $this->loadCart();
    }

    public function remove(string $key): void
    {
        $this->cartService()->remove($key);
        $this->loadCart();
    }

    public function clear(): void
    {
        $this->cartService()->clear();
        $this->reset(['cart', 'totale', 'centroCostoNome', 'fornitoreCode', 'confirmationNumber', 'notes']);
    }

    public static function getNavigationBadge(): ?string
    {
        $cart = session()->get(CatalogCartService::SESSION_KEY, []);
        $items = is_array($cart) && is_array($cart['items'] ?? null) ? $cart['items'] : [];
        $quantity = array_sum(array_map(
            static fn (mixed $item): int => is_array($item) ? max(0, (int) ($item['quantita'] ?? 0)) : 0,
            $items,
        ));

        return $quantity > 0 ? (string) $quantity : null;
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return 'primary';
    }

    public function proceed(): void
    {
        $this->validate([
            'confirmationNumber' => ['required', 'string', 'max:50', 'not_regex:/[\r\n]/'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [
            'confirmationNumber.required' => 'Inserisci il numero ordine cliente.',
            'confirmationNumber.max' => 'Il numero ordine cliente non puo superare 50 caratteri.',
            'notes.max' => 'Le note non possono superare 1000 caratteri.',
        ]);

        try {
            $contents = $this->cartService()->contents($this->user());
            $ordine = app(OrderSubmissionService::class)->submit(
                $this->user(),
                $contents['centro_costo_id'],
                $contents['items'],
                $this->confirmationNumber,
                $this->notes,
            );

            $this->clear();

            $notification = Notification::make()
                ->title(sprintf('Ordine #%d registrato', $ordine->getKey()))
                ->success();

            if ($ordine->email_stato === 'inviata') {
                $notification->body('La richiesta di preventivo e stata inviata al fornitore e all\'amministrazione.');
            } elseif ($ordine->email_stato === 'parziale') {
                $notification->body('L\'ordine e registrato. L\'email e stata inviata ai destinatari disponibili; manca ancora un recapito configurato.');
            } else {
                $notification->body('L\'ordine e registrato. La consegna email resta in attesa di configurazione o ritentativo.');
            }

            $notification->persistent()->send();
        } catch (ValidationException $exception) {
            Notification::make()
                ->title('Ordine non registrato')
                ->body((string) (collect($exception->errors())->flatten()->first() ?? 'I dati non sono validi.'))
                ->danger()
                ->send();
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()
                ->title('Ordine non registrato')
                ->body('Si e verificato un errore. Il carrello non e stato svuotato.')
                ->danger()
                ->persistent()
                ->send();
        }
    }

    private function loadCart(): void
    {
        if ($this->cartService()->isEmpty()) {
            $this->cart = [];
            $this->totale = 0;
            $this->centroCostoNome = null;
            $this->fornitoreCode = null;

            return;
        }

        try {
            $contents = $this->cartService()->contents($this->user());
            $this->cart = $contents['items'];
            $this->totale = $contents['totale'];
            $this->centroCostoNome = $contents['centro_costo_nome'];
            $this->fornitoreCode = $contents['fornitore_code'];
        } catch (ValidationException $exception) {
            $this->cartService()->clear();
            $this->cart = [];
            $this->totale = 0;
            $this->centroCostoNome = null;
            $this->fornitoreCode = null;

            Notification::make()
                ->title('Carrello svuotato')
                ->body((string) collect($exception->errors())->flatten()->first().' Riapri il catalogo per aggiornare la selezione.')
                ->warning()
                ->send();
        }
    }

    private function user(): User
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            abort(403);
        }

        return $user;
    }

    private function cartService(): CatalogCartService
    {
        return app(CatalogCartService::class);
    }
}
