<?php

namespace App\Filament\Resources\Listinos\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;

class ListinoForm
{
    public static function make(): array
    {
        return [
            TextInput::make('nome_listino')
                ->label('Nome listino')
                ->required()
                ->maxLength(255)
                ->columnSpanFull(),

            Textarea::make('descrizione')
                ->label('Descrizione')
                ->columnSpanFull(),

            Select::make('tipo')
                ->label('Tipo di listino')
                ->options([
                    'acquisto' => 'Acquisto (Fornitore)',
                    'vendita' => 'Vendita (Cliente / Centro di costo)',
                ])
                ->default('acquisto')
                ->live()
                ->required(),

            Select::make('fornitore_id')
                ->label('Fornitore (solo per listini di acquisto)')
                ->options(\App\Models\Fornitore::pluck('nome', 'id'))
                ->searchable()
                ->preload()
                ->visible(fn (callable $get) => $get('tipo') === 'acquisto')
                ->columnSpanFull(),

            Select::make('centro_costo_id')
                ->label('Centro di costo (solo per listini di vendita)')
                ->options(\App\Models\CentroCosto::pluck('nome', 'id'))
                ->searchable()
                ->preload()
                ->visible(fn (callable $get) => $get('tipo') === 'vendita')
                ->columnSpanFull(),

            Select::make('categoria_id')
                ->relationship('categoria', 'nome')
                ->nullable()
                ->label('Categoria (se vuoto vale per tutte)'),

            TextInput::make('sconto_percentuale')
                ->numeric()
                ->label('Sconto %')
                ->required(),

            DatePicker::make('valido_dal')
                ->label('Valido dal'),

            DatePicker::make('valido_al')
                ->label('Valido fino al')
                ->nullable(),

            Toggle::make('attivo')
                ->label('Attivo')
                ->default(true),
        ];
    }
}
