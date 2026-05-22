<x-filament::page>
    <x-filament::section heading="Ordine #{{ $record->id }}">

        {{-- TESTATA --}}
        <x-filament::card>
            <div class="grid grid-cols-3 gap-4 text-sm">
                <div>
                    <div class="text-gray-500">Stato</div>
                    <div class="font-medium">{{ ucfirst($record->stato) }}</div>
                </div>

                <div>
                    <div class="text-gray-500">Creato il</div>
                    <div class="font-medium">
                        {{ $record->created_at->format('d/m/Y H:i') }}
                    </div>
                </div>

                <div>
                    <div class="text-gray-500">Cliente</div>
                    <div class="font-medium">
                        {{ $record->user->name ?? '-' }}
                    </div>
                </div>

                <div>
                    <div class="text-gray-500">Centro di costo</div>
                    <div class="font-medium">
                        {{ $record->centroCosto->nome ?? '-' }}
                    </div>
                </div>

                <div>
                    <div class="text-gray-500">Totale netto</div>
                    <div class="font-medium">
                        € {{ number_format($record->totale_netto, 2, ',', '.') }}
                    </div>
                </div>

                <div>
                    <div class="text-gray-500">IVA</div>
                    <div class="font-medium">
                        € {{ number_format($record->iva_totale, 2, ',', '.') }}
                    </div>
                </div>

                <div>
                    <div class="text-gray-500">Totale lordo</div>
                    <div class="font-semibold text-lg">
                        € {{ number_format($record->totale_lordo, 2, ',', '.') }}
                    </div>
                </div>
            </div>
        </x-filament::card>

        {{-- RIGHE ORDINE --}}
        <x-filament::card class="mt-6">
            <table class="w-full text-sm">
                <thead class="border-b text-gray-500">
                    <tr>
                        <th class="text-left py-2">Prodotto</th>
                        <th class="text-center py-2">Q.tà</th>
                        <th class="text-right py-2">Prezzo</th>
                        <th class="text-right py-2">Netto</th>
                        <th class="text-right py-2">IVA</th>
                        <th class="text-right py-2">Totale</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    @foreach ($record->items as $item)
                        <tr>
                            <td class="py-2">
                                {{ $item->prodotto->nome ?? '—' }}
                            </td>
                            <td class="text-center py-2">
                                {{ $item->quantita }}
                            </td>
                            <td class="text-right py-2">
                                € {{ number_format($item->prezzo_unitario_lordo, 2, ',', '.') }}
                            </td>
                            <td class="text-right py-2">
                                € {{ number_format($item->totale_riga_netto, 2, ',', '.') }}
                            </td>
                            <td class="text-right py-2">
                                € {{ number_format($item->totale_riga_iva, 2, ',', '.') }}
                            </td>
                            <td class="text-right py-2 font-medium">
                                € {{ number_format($item->totale_riga_lordo, 2, ',', '.') }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-filament::card>

    </x-filament::section>
</x-filament::page>
