<x-filament::page>
    <x-filament::section heading="Riepilogo ordine">
        @if (empty($cart))
            <div class="py-10 text-center text-sm text-gray-500 dark:text-gray-400">
                Il carrello e vuoto.
            </div>
        @else
            <div class="mb-5 flex flex-wrap gap-x-8 gap-y-2 text-sm">
                <div>
                    <span class="text-gray-500 dark:text-gray-400">Centro di costo:</span>
                    <strong>{{ $centroCostoNome }}</strong>
                </div>
                <div>
                    <span class="text-gray-500 dark:text-gray-400">Fornitore:</span>
                    <strong>{{ $fornitoreCode }}</strong>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[760px] text-sm">
                    <thead class="border-b text-gray-500 dark:border-white/10 dark:text-gray-400">
                        <tr>
                            <th class="py-3 text-left font-medium">Articolo</th>
                            <th class="py-3 text-center font-medium">UDM</th>
                            <th class="py-3 text-center font-medium">Quantita</th>
                            <th class="py-3 text-right font-medium">Prezzo IVA incl.</th>
                            <th class="py-3 text-right font-medium">Totale</th>
                            <th class="w-12 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y dark:divide-white/10">
                        @foreach ($cart as $key => $item)
                            <tr wire:key="cart-row-{{ $key }}">
                                <td class="py-4 pr-4">
                                    <div class="font-medium">{{ $item['nome'] }}</div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400">
                                        Codice {{ $item['supplier_code'] }}
                                    </div>
                                </td>
                                <td class="py-4 text-center">{{ $item['unita'] }}</td>
                                <td class="py-4">
                                    <div class="flex items-center justify-center gap-2">
                                        <x-filament::icon-button
                                            icon="heroicon-o-minus"
                                            size="sm"
                                            wire:click="decrement('{{ $key }}')"
                                            label="Riduci quantita"
                                        />
                                        <span class="w-10 text-center font-medium">{{ $item['quantita'] }}</span>
                                        <x-filament::icon-button
                                            icon="heroicon-o-plus"
                                            size="sm"
                                            wire:click="increment('{{ $key }}')"
                                            label="Aumenta quantita"
                                        />
                                    </div>
                                </td>
                                <td class="py-4 text-right">
                                    &euro; {{ number_format((float) $item['prezzo_unitario'], 2, ',', '.') }}
                                </td>
                                <td class="py-4 text-right font-semibold">
                                    &euro; {{ number_format((float) $item['totale'], 2, ',', '.') }}
                                </td>
                                <td class="py-4 pl-3 text-right">
                                    <x-filament::icon-button
                                        color="danger"
                                        icon="heroicon-o-trash"
                                        wire:click="remove('{{ $key }}')"
                                        label="Rimuovi articolo"
                                    />
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-6 grid gap-6 border-t pt-6 dark:border-white/10 lg:grid-cols-[minmax(280px,1fr)_220px]">
                <div class="grid gap-5">
                    <div>
                        <label for="confirmation-number" class="block text-sm font-medium">
                            Numero ordine cliente <span class="text-danger-600">*</span>
                        </label>
                        <input
                            id="confirmation-number"
                            type="text"
                            wire:model="confirmationNumber"
                            class="mt-2 block w-full rounded-lg border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5"
                            maxlength="50"
                            placeholder="Numero o riferimento interno"
                        />
                        @error('confirmationNumber')
                            <p class="mt-2 text-sm text-danger-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="order-notes" class="block text-sm font-medium">Note</label>
                        <textarea
                            id="order-notes"
                            wire:model="notes"
                            rows="3"
                            maxlength="1000"
                            class="mt-2 block w-full rounded-lg border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5"
                        ></textarea>
                        @error('notes')
                            <p class="mt-2 text-sm text-danger-600">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <div class="flex flex-col items-end justify-between gap-5">
                    <div class="text-right">
                        <div class="text-sm text-gray-500 dark:text-gray-400">Totale IVA inclusa</div>
                        <div class="text-2xl font-semibold">&euro; {{ number_format($totale, 2, ',', '.') }}</div>
                    </div>

                    <div class="flex flex-wrap justify-end gap-3">
                        <x-filament::button
                            color="gray"
                            icon="heroicon-o-trash"
                            wire:click="clear"
                            wire:loading.attr="disabled"
                        >
                            Svuota
                        </x-filament::button>
                        <x-filament::button
                            color="primary"
                            icon="heroicon-o-paper-airplane"
                            wire:click="proceed"
                            wire:loading.attr="disabled"
                        >
                            Invia ordine
                        </x-filament::button>
                    </div>
                </div>
            </div>
        @endif
    </x-filament::section>
</x-filament::page>
