<?php

namespace App\Filament\Resources\Products\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Filament\Tables\Columns\ImageColumn;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
        ->columns([
        ImageColumn::make('immagine')
            ->label('Immagine')
            ->disk('public')
            ->height(40)
            ->toggleable()
            ->defaultImageUrl(url('/images/placeholder.png')), // immagine di fallback

            TextColumn::make('nome')->label('Nome')->searchable(),
            TextColumn::make('codice')->label('Codice')->searchable(),
            TextColumn::make('categoria.nome')->label('Categoria'),

            TextColumn::make('pdf_sicurezza')
                ->label('Scheda PDF')
                ->url(function ($record) {
                    return $record->pdf_sicurezza
                        ? asset('storage/' . $record->pdf_sicurezza)
                        : null;
                }, true)
                ->openUrlInNewTab()
                ->toggleable(),

            IconColumn::make('disponibile')
                ->label('Disponibile')
                ->boolean()
                ->trueIcon('heroicon-o-check')
                ->falseIcon('heroicon-o-x'),
        ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
