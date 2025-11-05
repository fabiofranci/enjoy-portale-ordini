<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Invio ordine</x-slot>
        <x-filament-panels::form wire:submit="submit">
            {{ $this->getForm() }}
            <div class="mt-4">
                <x-filament::button type="submit" icon="heroicon-o-paper-airplane">
                    Invia ordine
                </x-filament::button>
            </div>
        </x-filament-panels::form>
    </x-filament::section>
</x-filament-panels::page>
