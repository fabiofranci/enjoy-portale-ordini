<?php

namespace App\Filament\Resources\CentroCostos\Pages;

use App\Filament\Resources\CentroCostos\CentroCostoResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCentroCostos extends ListRecords
{
    protected static string $resource = CentroCostoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
