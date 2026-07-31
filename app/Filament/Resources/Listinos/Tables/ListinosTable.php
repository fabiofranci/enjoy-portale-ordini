<?php

namespace App\Filament\Resources\Listinos\Tables;

use Filament\Tables;
use Filament\Tables\Columns\TextColumn;

class ListinosTable
{
    public static function schema(): array
    {
        return [
            TextColumn::make('nome_listino')
                ->label('Listino')
                ->searchable()
                ->sortable(),
            TextColumn::make('fornitore.code')
                ->label('Fornitore')
                ->badge()
                ->sortable(),
            TextColumn::make('centroCosto.nome')->label('Centro di Costo'),
            TextColumn::make('categoria.nome')->label('Categoria'),
            Tables\Columns\BadgeColumn::make('tipo')
                ->label('Tipo')
                ->colors([
                    'success' => 'acquisto',
                    'info' => 'vendita',
                ])
                ->formatStateUsing(fn ($state) => ucfirst($state)),
            TextColumn::make('sconto_percentuale')->label('Sconto %'),
            TextColumn::make('valido_dal')->label('Valido dal')->date(),
            TextColumn::make('valido_al')->label('Valido al')->date(),
            Tables\Columns\IconColumn::make('attivo')
                ->label('Attivo')
                ->boolean(),
        ];
    }
}
