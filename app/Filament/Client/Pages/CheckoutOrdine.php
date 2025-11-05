<?php

namespace App\Filament\Client\Pages;

use App\Models\Ordine;
use App\Models\CentroCosto;
use App\Services\CartService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;

class CheckoutOrdine extends Page
{
    protected static ?string $navigationIcon = null; // niente nel menu
    protected static ?string $navigationLabel = 'Invio ordine';
    protected static ?string $slug = 'checkout';
    protected static string $view = 'filament.client.pages.checkout-ordine';

    public ?int $centro_costo_id = null;
    public ?string $riferimento_cliente = null;
    public ?string $note = null;
    public bool $extra_budget = false;

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function schema(Schema $schema): Schema
    {
        return $schema->form([
            Forms\Components\Select::make('centro_costo_id')
                ->label('Centro di costo')
                ->options(fn () => CentroCosto::query()
                    ->where('cliente_id', auth()->user()->cliente_id ?? null)
                    ->pluck('nome','id'))
                ->searchable()
                ->required(),
            Forms\Components\TextInput::make('riferimento_cliente')->label('Riferimento cliente'),
            Forms\Components\Textarea::make('note')->rows(4),
            Forms\Components\Toggle::make('extra_budget')->label('Extra budget'),
        ])->columns(2);
    }

    public function submit(): void
    {
        $ordine = CartService::getDraftFor(auth()->user());

        if ($ordine->items()->count() === 0) {
            Notification::make()->danger()->title('Il carrello è vuoto')->send();
            return;
        }

        DB::transaction(function () use ($ordine) {
            $ordine->update([
                'centro_costo_id'   => $this->centro_costo_id,
                'riferimento_cliente' => $this->riferimento_cliente,
                'note'              => $this->note,
                'extra_budget'      => $this->extra_budget,
            ]);

            // TODO: calcolo budget residuo (BudgetService) -> imposta stato
            // Semplificazione: se extra_budget = true => in attesa approvazione
            $stato = $this->extra_budget ? 'in_attesa_approvazione' : 'inviato';
            $ordine->update(['stato' => $stato]);

            // TODO: Generazione PDF e invio email admin
            // es.: $path = app(PdfService::class)->generaOrdine($ordine);
            // Mail::to(config('mail.admin'))->send(new OrdineInviatoMail($ordine));
        });

        Notification::make()->success()->title('Ordine inviato')->send();

        // redirect al riepilogo storico (da implementare in Fase 8) o al carrello vuoto
        $this->redirect(CatalogoProdotti::getUrl());
    }
}
