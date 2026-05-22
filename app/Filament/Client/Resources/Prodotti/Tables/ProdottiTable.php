<?php

// app/Filament/Client/Resources/Prodotti/Tables/ProdottiTable.php
namespace App\Filament\Client\Resources\Prodotti\Tables;

use App\Models\Product;
use Filament\Tables\Table;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;

class ProdottiTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('immagine')
                    ->label('Immagine')
                    ->disk('public')
                    ->height(60)
                    ->defaultImageUrl(url('/images/placeholder.png')),

                TextColumn::make('nome')->label('Nome')->searchable()->sortable(),
                TextColumn::make('codice')->label('Codice')->searchable(),
                TextColumn::make('categoria.nome')->label('Categoria')->sortable(),

                ViewColumn::make('unita_prezzo')
                    ->label('Unita e prezzo')
                    ->view('filament.client.prodotti.columns.unita-prezzo'),
            ]);
    }
}
