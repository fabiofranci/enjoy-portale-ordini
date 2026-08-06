<?php

namespace App\Filament\Resources\OrdineResource\Pages;

use App\Filament\Resources\OrdineResource;
use App\Services\Orders\OrderListExportService;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListOrdini extends ListRecords
{
    protected static string $resource = OrdineResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportPdf')
                ->label('Esporta PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->action(fn (): StreamedResponse => $this->export('pdf')),
            Action::make('exportExcel')
                ->label('Esporta Excel')
                ->icon('heroicon-o-table-cells')
                ->action(fn (): StreamedResponse => $this->export('xlsx')),
        ];
    }

    private function export(string $format): StreamedResponse
    {
        $dateFilter = $this->getTableFilterState('data_ordine') ?? [];
        $document = app(OrderListExportService::class)->export(
            $this->getTableQueryForExport(),
            $format,
            $dateFilter['da'] ?? null,
            $dateFilter['a'] ?? null,
        );

        return response()->streamDownload(
            static function () use ($document): void {
                echo $document['content'];
            },
            $document['filename'],
            [
                'Content-Type' => $document['mime'],
                'Cache-Control' => 'private, no-store, max-age=0',
            ],
        );
    }
}
