<?php

namespace App\Filament\Resources\Listinos\Pages;

use App\Filament\Resources\Listinos\ListinoResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListListinos extends ListRecords
{
    protected static string $resource = ListinoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
