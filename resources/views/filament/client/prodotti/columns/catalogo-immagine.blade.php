<div class="flex h-16 w-16 items-center justify-center overflow-hidden rounded-md bg-gray-100 dark:bg-gray-800">
    @if ($record->referenza->immagine_url)
        <img
            src="{{ $record->referenza->immagine_url }}"
            alt="{{ $record->referenza->descrizione }}"
            class="h-full w-full object-contain"
        >
    @else
        <x-heroicon-o-photo class="h-7 w-7 text-gray-400" aria-label="Immagine non disponibile" />
    @endif
</div>
