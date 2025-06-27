<?php

namespace App\Filament\Resources\Listinos\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ListinoForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('nome_listino')
                    ->required(),
                DatePicker::make('valido_dal'),
                DatePicker::make('valido_al'),
            ]);
    }
}
