<x-filament-panels::page>
    <form wire:submit="importCatalog" class="space-y-6">
        {{ $this->form }}

        <x-filament::button type="submit" icon="heroicon-o-arrow-up-tray">
            Avvia importazione
        </x-filament::button>
    </form>
</x-filament-panels::page>
