<?php

namespace App\Filament\Resources\OrdineResource\Pages;

use App\Filament\Resources\OrdineResource;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;

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

                    Text::make('cliente_nome')->label('Cliente'),
                    Text::make('centro_costo_nome')->label('Centro di costo'),
                    Text::make('fornitore_code')->label('Fornitore'),
                    Text::make('riferimento_cliente')
                        ->label('Numero ordine cliente')
                        ->placeholder('-'),
                    Text::make('email_stato')->label('Stato email'),
                ]),

            Section::make('Totale')
                ->columns(1)
                ->components([
                    Text::make('totale_lordo')->label('Totale IVA inclusa')->money('EUR'),
                ]),
        ]);
    }
}
