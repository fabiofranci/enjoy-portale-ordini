<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Models\Categoria;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Placeholder;
use Filament\Schemas\Schema;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([

            TextInput::make('nome')
                ->label('Nome prodotto')
                ->required(),

            TextInput::make('codice')
                ->label('Codice')
                ->required()
                ->unique(ignoreRecord: true),

            Select::make('categoria_id')
                ->label('Categoria')
                ->options(function () {
                    $categorie = Categoria::with('figli')->get();
                    $options = [];

                    $buildTree = function ($items, $prefix = '') use (&$buildTree, &$options) {
                        foreach ($items as $item) {
                            $options[$item->id] = $prefix . $item->nome;
                            $buildTree($item->figli, $prefix . '— ');
                        }
                    };

                    $radici = $categorie->whereNull('categoria_padre_id');
                    $buildTree($radici);

                    return $options;
                })
                ->searchable()
                ->preload()
                ->required(),

            TextInput::make('unita_misura')
                ->label('Unità di misura')
                ->required(),

            TextInput::make('prezzo_listino_base')
                ->label('Prezzo listino base (€)')
                ->numeric()
                ->step(0.01)
                ->prefix('€')
                ->dehydrated(false), // ⬅️ FONDAMENTALE

            Toggle::make('disponibile')
                ->label('Disponibile'),

            Textarea::make('descrizione')
                ->label('Descrizione')
                ->columnSpanFull(),

            FileUpload::make('immagine')
                ->label('Immagine')
                ->directory('prodotti/immagini')
                ->disk('public')
                ->image()
                ->imageEditor()
                ->previewable()
                ->downloadable(),

            FileUpload::make('pdf_sicurezza')
                ->label('Scheda Sicurezza (PDF)')
                ->directory('prodotti/pdf')
                ->acceptedFileTypes(['application/pdf'])
                ->disk('public')
                ->openable()
                ->downloadable(),

            Placeholder::make('info_listini')
                ->label('Gestione listini')
                ->content('Il prezzo del listino base viene salvato automaticamente. Gli altri listini sono gestiti nella sezione dedicata.')
                ->columnSpanFull(),

        ]);
    }
}
