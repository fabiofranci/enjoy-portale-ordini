<?php

declare(strict_types=1);

namespace App\Filament\Resources\ImportBatches\Pages;

use App\Filament\Pages\ImportaCatalogo;
use App\Filament\Resources\ImportBatches\ImportBatchResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListImportBatches extends ListRecords
{
    protected static string $resource = ImportBatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('import')
                ->label('Nuova importazione')
                ->icon('heroicon-o-arrow-up-tray')
                ->url(ImportaCatalogo::getUrl()),
        ];
    }
}
