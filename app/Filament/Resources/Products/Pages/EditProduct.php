<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use Filament\Resources\Pages\EditRecord;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    protected function fillForm(): void
    {
        parent::fillForm();

        $state = $this->form->getState();

        $state['prezzo_listino_base'] = $this->record->prezzoListinoBase();

        $this->form->fill($state);
    }

    protected function afterSave(): void
    {
        $state = $this->form->getRawState(); // ⬅️ IMPORTANTE

        if (array_key_exists('prezzo_listino_base', $state)) {
            $prezzo = str_replace(',', '.', $state['prezzo_listino_base']);

            if ($prezzo !== '') {
                $this->record->salvaPrezzoListinoBase(
                    (float) $prezzo
                );
            }
        }
    }
}
