<?php

namespace App\Filament\Resources\CentroCostos\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class CentroCostoForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('cliente_id')
                ->label('Cliente')
                ->relationship('cliente', 'nome')
                ->searchable()
                ->preload()
                ->required(),

            TextInput::make('nome')->required()->label('Nome centro di costo'),
            TextInput::make('descrizione')->label('Descrizione'),
            Textarea::make('indirizzo')
                ->label('Indirizzo di destinazione')
                ->rows(3)
                ->columnSpanFull(),
            TextInput::make('budget_annuale')->label('Budget annuale')->numeric()->prefix('€'),
            TextInput::make('budget_mensile')->label('Budget mensile')->numeric()->prefix('€'),
        ]);
    }
}
