<?php

namespace App\Filament\Resources\Clientes\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ClienteForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('nome')
                    ->required(),
                TextInput::make('partita_iva')
                    ->required(),
                TextInput::make('email')
                    ->email(),
                TextInput::make('telefono')
                    ->tel(),
                TextInput::make('indirizzo'),
            ]);
    }
}


