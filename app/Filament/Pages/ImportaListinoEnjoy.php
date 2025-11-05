<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Illuminate\Support\Facades\Log;
use Livewire\WithFileUploads;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ImportaListinoEnjoy extends Page implements HasForms
{
    use InteractsWithForms;
    use WithFileUploads;

    protected static ?string $navigationLabel = 'Importa Listino Enjoy';
    protected static ?string $title = 'Importa Listino Enjoy';
    protected string $view = 'filament.pages.importa-listino-enjoy';

    public ?array $data = [];
    public array $anteprima = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    protected function getFormSchema(): array
    {
        return [
            Forms\Components\FileUpload::make('file')
                ->label('File Excel (.xlsx)')
                ->acceptedFileTypes([
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    '.xlsx',
                ])
                ->required()
                ->disk('local')
                ->directory('uploads'),
        ];
    }

    protected function getFormModel(): string
    {
        return '';
    }

    protected function getFormStatePath(): string
    {
        return 'data';
    }

    public function import(): void
    {
        $fileData = $this->data['file'] ?? null;

        if (!is_array($fileData)) {
            Log::debug('File non valido o non ricevuto correttamente.', ['data' => $this->data]);
            $this->addError('file', 'File non valido o non ricevuto correttamente.');
            return;
        }

        $tempPath = collect($fileData)->first();
        $tempPath = is_array($tempPath) ? reset($tempPath) : $tempPath;

        if (!$tempPath || !file_exists($tempPath)) {
            Log::debug('Path del file non valido o non trovato.', ['path' => $tempPath]);
            $this->addError('file', 'Path del file non valido.');
            return;
        }

        try {
            $spreadsheet = IOFactory::load($tempPath);
            $worksheet = $spreadsheet->getActiveSheet();
            $rows = $worksheet->toArray();

            $this->anteprima = array_slice($rows, 0, 10);

            Log::debug('File importato correttamente.', ['righe' => $this->anteprima]);
        } catch (\Throwable $e) {
            Log::error('Errore durante l\'importazione del file Excel.', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->addError('file', 'Errore durante la lettura del file Excel.');
        }
    }
}
