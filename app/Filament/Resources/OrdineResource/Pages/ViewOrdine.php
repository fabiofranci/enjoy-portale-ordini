<?php

namespace App\Filament\Resources\OrdineResource\Pages;

use App\Filament\Resources\OrdineResource;
use App\Models\Ordine;
use App\Models\User;
use App\Services\Orders\OrderNotificationService;
use App\Services\Orders\OrderStatusService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Throwable;

class ViewOrdine extends ViewRecord
{
    protected static string $resource = OrdineResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('downloadPdf')
                ->label('PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->url(fn (): string => route('orders.documents.download', [
                    'ordine' => $this->record,
                    'format' => 'pdf',
                ])),
            Action::make('downloadExcel')
                ->label('Excel')
                ->icon('heroicon-o-table-cells')
                ->url(fn (): string => route('orders.documents.download', [
                    'ordine' => $this->record,
                    'format' => 'xlsx',
                ])),
            Action::make('resendEmail')
                ->label('Reinvia email')
                ->icon('heroicon-o-envelope')
                ->color('gray')
                ->requiresConfirmation()
                ->visible(fn (): bool => auth()->user()?->hasRole('admin') === true)
                ->action(function (): void {
                    $actor = auth()->user();

                    abort_unless($actor instanceof User, 403);

                    try {
                        $this->record = app(OrderNotificationService::class)
                            ->resend($this->record, $actor);

                        $successful = in_array($this->record->email_stato, ['inviata', 'parziale'], true);

                        Notification::make()
                            ->title($successful ? 'Email ordine reinviata' : 'Email ordine non inviata')
                            ->body($successful ? null : $this->record->email_last_error)
                            ->color($successful ? 'success' : 'danger')
                            ->send();
                    } catch (Throwable $exception) {
                        report($exception);

                        Notification::make()
                            ->title('Email ordine non inviata')
                            ->body($this->record->fresh()?->email_last_error)
                            ->danger()
                            ->send();
                    }
                }),
            Action::make('markAsFulfilled')
                ->label('Segna come evaso')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn (): bool => auth()->user()?->hasRole('admin') === true && ! $this->record->isEvaso())
                ->action(function (): void {
                    $actor = auth()->user();

                    abort_unless($actor instanceof User, 403);

                    $this->record = app(OrderStatusService::class)
                        ->markAsFulfilled($this->record, $actor);

                    Notification::make()
                        ->title('Ordine segnato come evaso')
                        ->success()
                        ->send();
                }),
        ];
    }

    public function schema(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Dati ordine')
                ->columns(3)
                ->components([
                    Text::make('id')->label('Ordine #'),
                    Text::make('stato')
                        ->label('Stato')
                        ->formatStateUsing(fn (string $state, Ordine $record): string => $record->statoLabel()),
                    Text::make('data_ordine')
                        ->label('Data ordine')
                        ->dateTime('d/m/Y H:i'),

                    Text::make('cliente_nome')->label('Cliente'),
                    Text::make('centro_costo_nome')->label('Centro di costo'),
                    Text::make('fornitore_code')->label('Fornitore'),
                    Text::make('riferimento_cliente')
                        ->label('Numero ordine cliente')
                        ->placeholder('-'),
                    Text::make('priorita')
                        ->label('Priorita')
                        ->formatStateUsing(fn (string $state, Ordine $record): string => $record->prioritaLabel()),
                    Text::make('inviato_da_nome')->label('Inviato da'),
                    Text::make('inviato_da_email')->label('Email mittente')->placeholder('-'),
                    Text::make('riferimento_richiedente')->label('Riferimento in loco')->placeholder('-'),
                    Text::make('indirizzo_destinazione')->label('Indirizzo di destinazione'),
                    Text::make('orari_consegna')->label('Orari di consegna')->placeholder('-'),
                    Text::make('note')->label('Note')->placeholder('-'),
                    Text::make('email_stato')->label('Stato email'),
                    Text::make('email_attempts')->label('Tentativi email'),
                    Text::make('email_last_attempt_at')
                        ->label('Ultimo tentativo email')
                        ->dateTime('d/m/Y H:i')
                        ->placeholder('-'),
                    Text::make('email_last_error')->label('Ultimo errore email')->placeholder('-'),
                ]),

            Section::make('Totale')
                ->columns(1)
                ->components([
                    Text::make('totale_lordo')->label('Totale IVA inclusa')->money('EUR'),
                ]),
        ]);
    }
}
