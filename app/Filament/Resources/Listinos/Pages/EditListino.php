<?php

namespace App\Filament\Resources\Listinos\Pages;

use App\Filament\Resources\Listinos\ListinoResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditListino extends EditRecord
{
    protected static string $resource = ListinoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
