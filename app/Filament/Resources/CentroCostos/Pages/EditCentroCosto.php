<?php

namespace App\Filament\Resources\CentroCostos\Pages;

use App\Filament\Resources\CentroCostos\CentroCostoResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCentroCosto extends EditRecord
{
    protected static string $resource = CentroCostoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
