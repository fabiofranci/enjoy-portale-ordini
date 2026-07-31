<?php

declare(strict_types=1);

namespace App\Filament\Resources\ImportBatches\Pages;

use App\Filament\Resources\ImportBatches\ImportBatchResource;
use App\Filament\Resources\Listinos\ListinoResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewImportBatch extends ViewRecord
{
    protected static string $resource = ImportBatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('listino')
                ->label('Apri listino')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->visible(fn (): bool => $this->record->listino_id !== null)
                ->url(fn (): string => ListinoResource::getUrl('view', [
                    'record' => $this->record->listino_id,
                ])),
        ];
    }
}
