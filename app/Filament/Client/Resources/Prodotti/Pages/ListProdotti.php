<?php

namespace App\Filament\Client\Resources\Prodotti\Pages;

use App\Filament\Client\Resources\Prodotti\ProdottoResource;
use App\Models\CentroCosto;
use App\Models\ListinoReferenza;
use App\Models\User;
use App\Services\Catalog\CatalogoClienteService;
use App\Services\Catalog\Exceptions\CatalogoClienteIncoerenteException;
use App\Services\Orders\CatalogCartService;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class ListProdotti extends ListRecords
{
    private const SESSION_KEY = 'catalogo.centro_costo_id';

    protected static string $resource = ProdottoResource::class;

    public ?int $centroCostoId = null;

    public function mount(): void
    {
        parent::mount();

        $user = $this->user();
        $service = $this->catalogService();
        $requestedId = request()->query('centro');

        if ($requestedId !== null) {
            $this->centroCostoId = $this->validCenterId($requestedId);
            $service->centroAccessibile($user, $this->centroCostoId);
        } elseif (session()->has(self::SESSION_KEY)) {
            $this->centroCostoId = $this->validCenterId(session(self::SESSION_KEY));
            $service->centroAccessibile($user, $this->centroCostoId);
        } else {
            $centri = $service->centriAccessibili($user);

            if ($centri->count() === 1) {
                $this->centroCostoId = (int) $centri->firstOrFail()->getKey();
            }
        }

        $cartService = app(CatalogCartService::class);

        if (! $cartService->isEmpty() && $cartService->selectedCenterId() !== null) {
            $this->centroCostoId = $cartService->selectedCenterId();
            $service->centroAccessibile($user, $this->centroCostoId);
        }

        if ($this->centroCostoId !== null) {
            session()->put(self::SESSION_KEY, $this->centroCostoId);
        }
    }

    public function getTableQuery(): Builder
    {
        $centroCosto = $this->selectedCentroCosto();

        if ($centroCosto === null) {
            return ListinoReferenza::query()->whereRaw('1 = 0');
        }

        try {
            return $this->catalogService()->query($centroCosto);
        } catch (CatalogoClienteIncoerenteException) {
            return ListinoReferenza::query()->whereRaw('1 = 0');
        }
    }

    public function getTitle(): string|Htmlable
    {
        return 'Catalogo';
    }

    public function getSubheading(): ?string
    {
        $centroCosto = $this->selectedCentroCosto();

        if ($centroCosto === null) {
            return $this->catalogEmptyDescription();
        }

        try {
            $service = $this->catalogService();
            $supplier = $centroCosto->fornitoreEffettivo();
            $count = $service->query($centroCosto)->count();
            $status = sprintf(
                '%s | Fornitore: %s | %d articoli disponibili.',
                $centroCosto->nome,
                $supplier?->code ?? 'non configurato',
                $count,
            );

            if ($service->hasUnavailableAssignments($centroCosto)) {
                $status .= ' Alcuni listini assegnati non sono al momento disponibili.';
            }

            return $status;
        } catch (CatalogoClienteIncoerenteException|DomainException) {
            return 'Il catalogo non e temporaneamente disponibile. Contatta l\'assistenza.';
        }
    }

    /**
     * @return array<string, string>
     */
    public function categoryOptions(): array
    {
        $centroCosto = $this->selectedCentroCosto();

        if ($centroCosto === null) {
            return [];
        }

        try {
            return $this->catalogService()->categoryOptions($centroCosto);
        } catch (CatalogoClienteIncoerenteException) {
            return [];
        }
    }

    public function catalogEmptyHeading(): string
    {
        $centroCosto = $this->selectedCentroCosto();

        if ($centroCosto === null) {
            return $this->catalogService()->centriAccessibili($this->user())->isEmpty()
                ? 'Nessun centro di costo disponibile'
                : 'Seleziona un centro di costo';
        }

        try {
            $this->catalogService()->query($centroCosto)->exists();
        } catch (CatalogoClienteIncoerenteException) {
            return 'Catalogo temporaneamente non disponibile';
        }

        return 'Nessun articolo disponibile';
    }

    public function catalogEmptyDescription(): string
    {
        $centroCosto = $this->selectedCentroCosto();

        if ($centroCosto === null) {
            return $this->catalogService()->centriAccessibili($this->user())->isEmpty()
                ? 'Non risultano centri di costo associati al tuo profilo.'
                : 'Scegli il centro di costo da consultare.';
        }

        try {
            $this->catalogService()->query($centroCosto)->exists();

            if ($this->catalogService()->hasUnavailableAssignments($centroCosto)) {
                return 'I listini assegnati non sono al momento disponibili.';
            }
        } catch (DomainException) {
            return 'Il catalogo non e temporaneamente disponibile.';
        }

        return 'Non risultano listini attivi con articoli ordinabili.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('selezionaCentro')
                ->label('Cambia centro')
                ->icon('heroicon-o-building-office-2')
                ->visible(fn (): bool => $this->catalogService()
                    ->centriAccessibili($this->user())
                    ->count() > 1)
                ->fillForm(fn (): array => ['centro_costo_id' => $this->centroCostoId])
                ->schema([
                    Select::make('centro_costo_id')
                        ->label('Centro di costo')
                        ->options(fn (): array => $this->centerOptions())
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $centroCostoId = $this->validCenterId($data['centro_costo_id'] ?? null);
                    $this->catalogService()->centroAccessibile($this->user(), $centroCostoId);

                    $cartService = app(CatalogCartService::class);

                    if (! $cartService->isEmpty() && $cartService->selectedCenterId() !== $centroCostoId) {
                        Notification::make()
                            ->title('Centro di costo non modificato')
                            ->body('Svuota il carrello prima di selezionare un altro centro di costo.')
                            ->warning()
                            ->send();

                        return;
                    }

                    $this->centroCostoId = $centroCostoId;
                    session()->put(self::SESSION_KEY, $centroCostoId);
                    $this->resetTable();
                }),
        ];
    }

    public function addToCart(ListinoReferenza $record): void
    {
        $centroCosto = $this->selectedCentroCosto();

        if ($centroCosto === null) {
            Notification::make()
                ->title('Seleziona un centro di costo')
                ->warning()
                ->send();

            return;
        }

        app(CatalogCartService::class)->add(
            $this->user(),
            (int) $centroCosto->getKey(),
            (int) $record->getKey(),
        );

        Notification::make()
            ->title('Articolo aggiunto al carrello')
            ->success()
            ->send();
    }

    private function selectedCentroCosto(): ?CentroCosto
    {
        if ($this->centroCostoId === null) {
            return null;
        }

        return $this->catalogService()->centroAccessibile(
            $this->user(),
            $this->centroCostoId,
        );
    }

    /**
     * @return array<int, string>
     */
    private function centerOptions(): array
    {
        return $this->catalogService()
            ->centriAccessibili($this->user())
            ->mapWithKeys(static fn (CentroCosto $centroCosto): array => [
                $centroCosto->getKey() => $centroCosto->nome,
            ])
            ->all();
    }

    private function validCenterId(mixed $value): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 1) {
            abort(403);
        }

        return (int) $value;
    }

    private function user(): User
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            abort(403);
        }

        return $user;
    }

    private function catalogService(): CatalogoClienteService
    {
        return app(CatalogoClienteService::class);
    }
}
