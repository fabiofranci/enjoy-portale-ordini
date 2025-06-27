<?php

namespace App\Filament\Resources\Listinos\Pages;

use App\Filament\Resources\Listinos\ListinoResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewListino extends ViewRecord
{
    protected static string $resource = ListinoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
