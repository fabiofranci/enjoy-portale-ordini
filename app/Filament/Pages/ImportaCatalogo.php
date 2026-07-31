<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Resources\ImportBatches\ImportBatchResource;
use App\Models\Fornitore;
use App\Services\Imports\Catalog\CatalogPersistentImportService;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Storage;
use Livewire\WithFileUploads;
use Throwable;

class ImportaCatalogo extends Page implements HasForms
{
    use InteractsWithForms;
    use WithFileUploads;

    protected static ?string $navigationLabel = 'Importa catalogo';

    protected static ?string $title = 'Importa catalogo fornitore';

    protected string $view = 'filament.pages.importa-catalogo';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('admin') === true;
    }

    /**
     * @return array<int, mixed>
     */
    protected function getFormSchema(): array
    {
        return [
            Select::make('supplier_code')
                ->label('Fornitore')
                ->options(fn (): array => Fornitore::query()
                    ->whereIn('code', ['ICA', 'IGROUP'])
                    ->orderBy('code')
                    ->pluck('nome', 'code')
                    ->all())
                ->live()
                ->required(),
            TextInput::make('list_name')
                ->label('Nome listino')
                ->required()
                ->maxLength(255),
            Select::make('profile')
                ->label('Profilo')
                ->options(['scuole' => 'Scuole'])
                ->visible(fn (callable $get): bool => $get('supplier_code') === 'ICA')
                ->dehydratedWhenHidden(false)
                ->required(fn (callable $get): bool => $get('supplier_code') === 'ICA'),
            FileUpload::make('file')
                ->label('File catalogo')
                ->acceptedFileTypes([
                    'text/csv',
                    'application/vnd.ms-excel',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                ])
                ->disk('local')
                ->directory('imports/cataloghi')
                ->storeFileNamesIn('original_file_name')
                ->required(),
        ];
    }

    protected function getFormStatePath(): string
    {
        return 'data';
    }

    public function importCatalog(CatalogPersistentImportService $service): void
    {
        $state = $this->form->getState();
        $storedPath = $this->firstFileState($state['file'] ?? null);

        if ($storedPath === null) {
            $this->addError('data.file', 'File non disponibile.');

            return;
        }

        try {
            $batch = $service->import(
                supplierCode: (string) $state['supplier_code'],
                listName: (string) $state['list_name'],
                filePath: Storage::disk('local')->path($storedPath),
                profile: $state['profile'] ?? null,
                userId: auth()->id(),
                originalFileName: $this->firstFileState($state['original_file_name'] ?? null),
            );

            Notification::make()
                ->title('Catalogo importato')
                ->success()
                ->send();

            $this->redirect(
                ImportBatchResource::getUrl('view', ['record' => $batch]),
                navigate: true
            );
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()
                ->title('Importazione non completata')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        } finally {
            Storage::disk('local')->delete($storedPath);
        }
    }

    private function firstFileState(mixed $state): ?string
    {
        if (is_string($state) && $state !== '') {
            return $state;
        }

        if (! is_array($state)) {
            return null;
        }

        foreach ($state as $value) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
