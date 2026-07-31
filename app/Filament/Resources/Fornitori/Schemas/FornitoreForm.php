<?php

namespace App\Filament\Resources\Fornitori\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class FornitoreForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->schema([
            TextInput::make('code')
                ->label('Codice')
                ->required()
                ->maxLength(64)
                ->unique(ignoreRecord: true)
                ->columnSpan(1),

            TextInput::make('nome')
                ->label('Nome')
                ->required()
                ->columnSpanFull(),

            Toggle::make('attivo')
                ->label('Attivo')
                ->default(true),

            TextInput::make('partita_iva')
                ->label('Partita IVA')
                ->maxLength(20)
                ->columnSpan(1),

            TextInput::make('email')
                ->label('Email')
                ->email()
                ->maxLength(100)
                ->columnSpan(1),

            TextInput::make('telefono')
                ->label('Telefono')
                ->maxLength(30)
                ->columnSpan(1),

            TextInput::make('indirizzo')
                ->label('Indirizzo')
                ->maxLength(255)
                ->columnSpan(1),
        ]);
    }
}
