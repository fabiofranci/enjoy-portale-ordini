<x-filament::page>
    @php($orders = $this->orders())

    @if ($orders->isEmpty())
        <x-filament::section>
            <div class="py-10 text-center text-sm text-gray-500 dark:text-gray-400">
                Non risultano ancora ordini.
            </div>
        </x-filament::section>
    @else
        <div class="space-y-5">
            @foreach ($orders as $order)
                <x-filament::section>
                    <x-slot name="heading">
                        Ordine {{ $order->riferimento_cliente }}
                    </x-slot>
                    <x-slot name="description">
                        {{ $order->created_at->format('d/m/Y H:i') }}
                        &middot; {{ $order->centro_costo_nome ?? '-' }}
                        &middot; {{ $order->fornitore_code ?? '-' }}
                    </x-slot>

                    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                        <div class="text-sm">
                            <span class="text-gray-500 dark:text-gray-400">Stato:</span>
                            <strong>{{ $order->stato === 'inviato' ? 'Registrato' : ucfirst($order->stato) }}</strong>
                        </div>
                        <div class="text-lg font-semibold">
                            &euro; {{ number_format((float) $order->totale_lordo, 2, ',', '.') }} IVA inclusa
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[620px] text-sm">
                            <thead class="border-b text-gray-500 dark:border-white/10 dark:text-gray-400">
                                <tr>
                                    <th class="py-2 text-left font-medium">Codice</th>
                                    <th class="py-2 text-left font-medium">Articolo</th>
                                    <th class="py-2 text-center font-medium">Q.t&agrave;</th>
                                    <th class="py-2 text-right font-medium">Totale</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y dark:divide-white/10">
                                @foreach ($order->items as $item)
                                    <tr>
                                        <td class="py-2 pr-4">{{ $item->supplier_code }}</td>
                                        <td class="py-2 pr-4">{{ $item->descrizione }}</td>
                                        <td class="py-2 text-center">{{ $item->quantita }} {{ $item->unita }}</td>
                                        <td class="py-2 text-right">
                                            &euro; {{ number_format((float) $item->totale_riga_lordo, 2, ',', '.') }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-filament::section>
            @endforeach
        </div>
    @endif
</x-filament::page>
