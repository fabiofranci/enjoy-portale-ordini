<?php

namespace App\Filament\Resources\OrdineResource\Pages;

use App\Filament\Resources\OrdineResource;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;

class ViewOrdine extends ViewRecord
{
    protected static string $resource = OrdineResource::class;

    public function schema(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Dati ordine')
                ->columns(3)
                ->components([
                    Text::make('id')->label('Ordine #'),
                    Text::make('stato')->label('Stato'),
                    Text::make('created_at')
                        ->label('Creato il')
                        ->dateTime('d/m/Y H:i'),

                    Text::make('user.name')->label('Cliente'),
                    Text::make('centroCosto.nome')->label('Centro di costo'),
                    Text::make('riferimento_cliente')
                        ->label('Conferma ordine')
                        ->placeholder('-'),
                ]),

            Section::make('Totali')
                ->columns(3)
                ->components([
                    Text::make('totale_netto')->label('Totale netto')->money('EUR'),
                    Text::make('iva_totale')->label('IVA')->money('EUR'),
                    Text::make('totale_lordo')->label('Totale lordo')->money('EUR'),
                ]),
        ]);
    }
}
