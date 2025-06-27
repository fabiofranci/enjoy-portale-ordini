<?php

namespace App\Filament\Resources\Categorias\Schemas;

use App\Models\Categoria;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class CategoriaForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('nome')
                ->required(),

            Select::make('categoria_padre_id')
                ->label('Categoria padre')
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
                ->nullable(),
        ]);
    }
}
