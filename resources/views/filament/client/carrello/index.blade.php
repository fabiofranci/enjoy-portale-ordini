<x-filament::page>
    <x-filament::section heading="Riepilogo ordine">

        <x-filament::card>

            {{-- HEADER COLONNE --}}
            <div
                class="pb-3 mb-3 border-b text-sm font-medium text-gray-500"
                style="display:grid; grid-template-columns: 3fr 0.8fr 1.4fr 1.4fr 1.4fr 40px; gap:12px;"
            >
                <div>Prodotto</div>
                <div class="text-center">UDM</div>
                <div class="text-center">Quantità</div>
                <div class="text-right">Prezzo</div>
                <div class="text-right">Totale</div>
                <div></div>
            </div>

            {{-- RIGHE PRODOTTO --}}
            @foreach ($cart as $key => $item)
                <div
                    class="py-3 border-b"
                    style="display:grid; grid-template-columns: 3fr 0.8fr 1.4fr 1.4fr 1.4fr 40px; gap:12px; align-items:center;"
                >

                    {{-- PRODOTTO --}}
                    <div>
                        <div class="font-medium leading-tight">
                            {{ $item['nome'] }}
                        </div>

                        @if(!empty($item['descrizione']))
                            <div class="text-xs text-gray-500">
                                {{ $item['descrizione'] }}
                            </div>
                        @endif
                    </div>

                    {{-- UDM --}}
                    <div class="text-center text-sm font-medium">
                        {{ $item['unita'] ?? 'NR' }}
                    </div>

                    {{-- QUANTITÀ --}}
                    <div class="flex justify-center items-center gap-1">
                        <x-filament::button
                            size="xs"
                            icon="heroicon-o-minus"
                            wire:click="decrement('{{ $key }}')"
                        />

                        <span class="px-2 text-sm font-medium">
                            {{ $item['quantita'] }}
                        </span>

                        <x-filament::button
                            size="xs"
                            icon="heroicon-o-plus"
                            wire:click="increment('{{ $key }}')"
                        />
                    </div>

                    {{-- PREZZO UNITARIO --}}
                    <div class="text-right">
                        € {{ number_format($item['prezzo_unitario'], 2, ',', '.') }}
                    </div>

                    {{-- TOTALE RIGA --}}
                    <div class="text-right font-semibold">
                        € {{ number_format($item['prezzo_unitario'] * $item['quantita'], 2, ',', '.') }}
                    </div>

                    {{-- RIMUOVI --}}
                    <div class="text-right">
                        <x-filament::button
                            size="xs"
                            color="danger"
                            icon="heroicon-o-trash"
                            wire:click="remove('{{ $key }}')"
                        />
                    </div>
                </div>
            @endforeach

            {{-- FOOTER ORDINE --}}
            <div
                class="mt-6 pt-4 border-t"
                style="display:grid; grid-template-columns: 1fr auto; gap:24px; align-items:end;"
            >
                <div class="text-right">
                    <div class="text-sm text-gray-500">
                        Totale ordine
                    </div>
                    <div class="text-2xl font-semibold">
                        € {{ number_format($totale, 2, ',', '.') }}
                    </div>
                </div>

                <div class="flex gap-3">
                    <x-filament::button
                        color="danger"
                        icon="heroicon-o-trash"
                        wire:click="clear"
                    >
                        Svuota
                    </x-filament::button>

                    <x-filament::button
                        color="primary"
                        icon="heroicon-o-paper-airplane"
                        wire:click="proceed"
                    >
                        Invia ordine
                    </x-filament::button>
                </div>
            </div>

        </x-filament::card>

    </x-filament::section>
</x-filament::page>
