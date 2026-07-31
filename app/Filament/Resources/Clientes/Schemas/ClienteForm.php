<?php

namespace App\Filament\Resources\Clientes\Schemas;

use App\Models\Cliente;
use App\Models\User;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Password;

class ClienteForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('nome')
                    ->required()
                    ->maxLength(255),
                TextInput::make('partita_iva')
                    ->required(),
                TextInput::make('email')
                    ->label('Email')
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->unique(
                        table: User::class,
                        column: 'email',
                        ignorable: fn (?Cliente $record): ?User => $record?->accessUser,
                    )
                    ->dehydrateStateUsing(static fn (string $state): string => mb_strtolower(trim($state))),
                TextInput::make('access_password')
                    ->label('Password di accesso')
                    ->password()
                    ->revealable(filament()->arePasswordsRevealable())
                    ->rule(Password::min(8))
                    ->autocomplete('new-password')
                    ->required(fn (?Cliente $record): bool => $record?->accessUser()->exists() !== true)
                    ->dehydrated(static fn (?string $state): bool => filled($state))
                    ->live(debounce: 500)
                    ->same('access_password_confirmation'),
                TextInput::make('access_password_confirmation')
                    ->label('Conferma password')
                    ->password()
                    ->revealable(filament()->arePasswordsRevealable())
                    ->autocomplete('new-password')
                    ->required()
                    ->visible(static fn (Get $get): bool => filled($get('access_password')))
                    ->dehydrated(false),
                TextInput::make('telefono')
                    ->tel(),
                TextInput::make('indirizzo'),
            ]);
    }
}
