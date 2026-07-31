<x-filament-panels::page>
    @if (empty($cart))
        <x-filament::section>
            <div class="flex min-h-72 flex-col items-center justify-center px-6 py-12 text-center">
                <div class="mb-5 flex size-14 items-center justify-center rounded-full bg-gray-100 text-gray-500 dark:bg-white/5 dark:text-gray-400">
                    <x-filament::icon icon="heroicon-o-shopping-cart" class="size-7" />
                </div>
                <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Il carrello è vuoto</h2>
                <p class="mt-2 max-w-md text-sm text-gray-500 dark:text-gray-400">
                    Gli articoli selezionati dal catalogo compariranno qui.
                </p>
                <x-filament::button
                    class="mt-6"
                    icon="heroicon-o-arrow-left"
                    tag="a"
                    :href="\App\Filament\Client\Resources\Prodotti\ProdottoResource::getUrl('index')"
                >
                    Torna al catalogo
                </x-filament::button>
            </div>
        </x-filament::section>
    @else
        <div class="space-y-6">
            <div class="flex flex-wrap items-center gap-3 border-b border-gray-200 pb-5 dark:border-white/10">
                <div class="flex min-w-0 items-center gap-3 rounded-lg bg-gray-100 px-4 py-3 dark:bg-white/5">
                    <x-filament::icon icon="heroicon-o-building-office-2" class="size-5 shrink-0 text-gray-500 dark:text-gray-400" />
                    <div class="min-w-0">
                        <div class="text-xs font-medium text-gray-500 dark:text-gray-400">Centro di costo</div>
                        <div class="truncate text-sm font-semibold text-gray-950 dark:text-white">{{ $centroCostoNome }}</div>
                    </div>
                </div>

                <div class="flex items-center gap-3 rounded-lg bg-gray-100 px-4 py-3 dark:bg-white/5">
                    <x-filament::icon icon="heroicon-o-truck" class="size-5 shrink-0 text-gray-500 dark:text-gray-400" />
                    <div>
                        <div class="text-xs font-medium text-gray-500 dark:text-gray-400">Fornitore</div>
                        <div class="text-sm font-semibold text-gray-950 dark:text-white">{{ $fornitoreCode }}</div>
                    </div>
                </div>

                <div class="ml-auto text-sm text-gray-500 dark:text-gray-400">
                    {{ count($cart) }} {{ count($cart) === 1 ? 'articolo' : 'articoli' }}
                </div>
            </div>

            <div class="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
                <x-filament::section>
                    <x-slot name="heading">Articoli nel carrello</x-slot>
                    <x-slot name="description">Prezzi unitari e totali IVA inclusa</x-slot>

                    <div class="hidden grid-cols-[minmax(10rem,1fr)_3rem_7rem_5.5rem_5.5rem_2rem] gap-2 border-b border-gray-200 pb-3 text-xs font-semibold uppercase text-gray-500 dark:border-white/10 dark:text-gray-400 md:grid">
                        <div>Articolo</div>
                        <div class="text-center">UDM</div>
                        <div class="text-center">Quantit&agrave;</div>
                        <div class="text-right">Prezzo</div>
                        <div class="text-right">Totale</div>
                        <div></div>
                    </div>

                    <div class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach ($cart as $key => $item)
                            <div
                                wire:key="cart-row-{{ $key }}"
                                class="grid grid-cols-2 items-center gap-x-4 gap-y-5 py-5 first:pt-1 last:pb-1 md:grid-cols-[minmax(10rem,1fr)_3rem_7rem_5.5rem_5.5rem_2rem] md:gap-2"
                            >
                                <div class="col-span-2 flex min-w-0 items-start justify-between gap-4 md:col-span-1 md:block">
                                    <div class="min-w-0">
                                        <div class="font-semibold leading-6 text-gray-950 dark:text-white">{{ $item['nome'] }}</div>
                                        <div class="mt-1 font-mono text-xs text-gray-500 dark:text-gray-400">
                                            Cod. {{ $item['supplier_code'] }}
                                        </div>
                                    </div>
                                    <div class="md:hidden">
                                        <x-filament::icon-button
                                            color="danger"
                                            icon="heroicon-o-trash"
                                            size="sm"
                                            wire:click="remove('{{ $key }}')"
                                            wire:loading.attr="disabled"
                                            wire:target="remove('{{ $key }}')"
                                            label="Rimuovi articolo"
                                            tooltip="Rimuovi articolo"
                                        />
                                    </div>
                                </div>

                                <div>
                                    <div class="mb-1 text-xs font-medium text-gray-500 dark:text-gray-400 md:hidden">UDM</div>
                                    <div class="md:text-center">
                                        <x-filament::badge color="gray">{{ $item['unita'] }}</x-filament::badge>
                                    </div>
                                </div>

                                <div class="justify-self-end md:justify-self-center">
                                    <div class="mb-1 text-right text-xs font-medium text-gray-500 dark:text-gray-400 md:hidden">Quantit&agrave;</div>
                                    <div class="flex h-9 w-[8.5rem] items-center justify-between rounded-lg border border-gray-200 bg-white px-1 shadow-sm dark:border-white/10 dark:bg-white/5 md:w-28">
                                        <x-filament::icon-button
                                            icon="heroicon-o-minus"
                                            size="sm"
                                            wire:click="decrement('{{ $key }}')"
                                            wire:loading.attr="disabled"
                                            wire:target="decrement('{{ $key }}')"
                                            label="Riduci quantit&agrave;"
                                            tooltip="Riduci quantit&agrave;"
                                        />
                                        <span class="w-10 text-center text-sm font-semibold tabular-nums text-gray-950 dark:text-white">
                                            {{ $item['quantita'] }}
                                        </span>
                                        <x-filament::icon-button
                                            icon="heroicon-o-plus"
                                            size="sm"
                                            wire:click="increment('{{ $key }}')"
                                            wire:loading.attr="disabled"
                                            wire:target="increment('{{ $key }}')"
                                            label="Aumenta quantit&agrave;"
                                            tooltip="Aumenta quantit&agrave;"
                                        />
                                    </div>
                                </div>

                                <div>
                                    <div class="mb-1 text-xs font-medium text-gray-500 dark:text-gray-400 md:hidden">Prezzo unitario</div>
                                    <div class="whitespace-nowrap text-sm tabular-nums text-gray-700 dark:text-gray-300 md:text-right">
                                        &euro; {{ number_format((float) $item['prezzo_unitario'], 2, ',', '.') }}
                                    </div>
                                </div>

                                <div class="text-right">
                                    <div class="mb-1 text-xs font-medium text-gray-500 dark:text-gray-400 md:hidden">Totale</div>
                                    <div class="whitespace-nowrap text-sm font-semibold tabular-nums text-gray-950 dark:text-white">
                                        &euro; {{ number_format((float) $item['totale'], 2, ',', '.') }}
                                    </div>
                                </div>

                                <div class="hidden justify-end md:flex">
                                    <x-filament::icon-button
                                        color="danger"
                                        icon="heroicon-o-trash"
                                        size="sm"
                                        wire:click="remove('{{ $key }}')"
                                        wire:loading.attr="disabled"
                                        wire:target="remove('{{ $key }}')"
                                        label="Rimuovi articolo"
                                        tooltip="Rimuovi articolo"
                                    />
                                </div>
                            </div>
                        @endforeach
                    </div>
                </x-filament::section>

                <div class="xl:sticky xl:top-6">
                    <x-filament::section>
                        <x-slot name="heading">Conferma ordine</x-slot>
                        <x-slot name="description">Completa i dati prima dell'invio</x-slot>

                        <div class="space-y-5">
                            <div>
                                <label for="confirmation-number" class="mb-2 block text-sm font-medium text-gray-950 dark:text-white">
                                    Numero ordine cliente <span class="text-danger-600">*</span>
                                </label>
                                <x-filament::input.wrapper
                                    prefix-icon="heroicon-o-hashtag"
                                    :valid="! $errors->has('confirmationNumber')"
                                >
                                    <x-filament::input
                                        id="confirmation-number"
                                        type="text"
                                        wire:model="confirmationNumber"
                                        maxlength="50"
                                        autocomplete="off"
                                        placeholder="Es. PO-2026/001"
                                    />
                                </x-filament::input.wrapper>
                                @error('confirmationNumber')
                                    <p class="mt-2 text-sm text-danger-600">{{ $message }}</p>
                                @enderror
                            </div>

                            <div>
                                <label for="order-notes" class="mb-2 block text-sm font-medium text-gray-950 dark:text-white">Note</label>
                                <x-filament::input.wrapper :valid="! $errors->has('notes')">
                                    <textarea
                                        id="order-notes"
                                        wire:model="notes"
                                        rows="4"
                                        maxlength="1000"
                                        class="fi-input block w-full resize-y border-none bg-transparent px-3 py-2 text-base text-gray-950 outline-none transition duration-75 placeholder:text-gray-400 focus:ring-0 disabled:text-gray-500 dark:text-white dark:placeholder:text-gray-500 sm:text-sm"
                                        placeholder="Indicazioni per l'ordine"
                                    ></textarea>
                                </x-filament::input.wrapper>
                                @error('notes')
                                    <p class="mt-2 text-sm text-danger-600">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="border-y border-gray-200 py-5 dark:border-white/10">
                                <div class="flex items-end justify-between gap-4">
                                    <div class="text-sm font-medium text-gray-500 dark:text-gray-400">Totale IVA inclusa</div>
                                    <div class="whitespace-nowrap text-2xl font-bold tabular-nums text-gray-950 dark:text-white">
                                        &euro; {{ number_format($totale, 2, ',', '.') }}
                                    </div>
                                </div>
                            </div>

                            <x-filament::button
                                class="w-full"
                                icon="heroicon-o-paper-airplane"
                                size="lg"
                                wire:click="proceed"
                                wire:loading.attr="disabled"
                                wire:target="proceed"
                            >
                                Conferma e invia ordine
                            </x-filament::button>

                            <x-filament::button
                                class="w-full"
                                color="gray"
                                icon="heroicon-o-trash"
                                wire:click="clear"
                                wire:loading.attr="disabled"
                                wire:target="clear"
                            >
                                Svuota carrello
                            </x-filament::button>
                        </div>
                    </x-filament::section>
                </div>
            </div>
        </div>
    @endif
</x-filament-panels::page>
