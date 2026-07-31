<?php

declare(strict_types=1);

namespace App\Filament\Resources\ImportBatches;

use App\Filament\Resources\ImportBatches\Pages\ListImportBatches;
use App\Filament\Resources\ImportBatches\Pages\ViewImportBatch;
use App\Models\ImportBatch;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ImportBatchResource extends Resource
{
    protected static ?string $model = ImportBatch::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $navigationLabel = 'Import Cataloghi';

    protected static ?int $navigationSort = 10;

    protected static ?string $modelLabel = 'Import catalogo';

    protected static ?string $pluralModelLabel = 'Import Cataloghi';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('Avviato')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('fornitore.code')
                    ->label('Fornitore')
                    ->badge()
                    ->sortable(),
                TextColumn::make('listino.nome_listino')
                    ->label('Listino')
                    ->searchable(),
                TextColumn::make('nome_file_originale')
                    ->label('File')
                    ->searchable(),
                TextColumn::make('stato')
                    ->label('Stato')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        ImportBatch::STATUS_COMPLETED => 'success',
                        ImportBatch::STATUS_FAILED => 'danger',
                        default => 'warning',
                    }),
                TextColumn::make('righe_lette')
                    ->label('Righe')
                    ->numeric(),
                TextColumn::make('referenze_create')
                    ->label('Nuove')
                    ->numeric(),
                TextColumn::make('referenze_aggiornate')
                    ->label('Aggiornate')
                    ->numeric(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('fornitore.code')->label('Fornitore')->badge(),
            TextEntry::make('listino.nome_listino')->label('Listino'),
            TextEntry::make('nome_file_originale')->label('File'),
            TextEntry::make('file_hash')->label('SHA-256')->copyable(),
            TextEntry::make('profilo')->label('Profilo')->placeholder('-'),
            TextEntry::make('stato')->label('Stato')->badge(),
            TextEntry::make('righe_lette')->label('Righe lette')->numeric(),
            TextEntry::make('referenze_create')->label('Referenze create')->numeric(),
            TextEntry::make('referenze_aggiornate')->label('Referenze aggiornate')->numeric(),
            TextEntry::make('prezzi_creati')->label('Prezzi creati')->numeric(),
            TextEntry::make('prezzi_aggiornati')->label('Prezzi aggiornati')->numeric(),
            TextEntry::make('righe_ignorate')->label('Righe ignorate')->numeric(),
            TextEntry::make('warnings')
                ->label('Warning')
                ->listWithLineBreaks()
                ->placeholder('-')
                ->columnSpanFull(),
            TextEntry::make('errori')
                ->label('Errori')
                ->listWithLineBreaks()
                ->placeholder('-')
                ->columnSpanFull(),
            TextEntry::make('iniziato_il')->label('Inizio')->dateTime('d/m/Y H:i:s'),
            TextEntry::make('completato_il')->label('Fine')->dateTime('d/m/Y H:i:s'),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListImportBatches::route('/'),
            'view' => ViewImportBatch::route('/{record}'),
        ];
    }
}
