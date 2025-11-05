<x-filament::page>
    <form wire:submit.prevent="import" class="space-y-6">
        {{ $this->form }}

        <x-filament::button type="submit">
            Importa Listino
        </x-filament::button>
    </form>
    @if ($anteprima)
        <x-filament::card class="mt-6">
            <x-filament::section heading="Anteprima dati letti dal file">
                <div class="overflow-auto">
                    <table class="w-full text-sm text-left rtl:text-right text-gray-500 dark:text-gray-400 border border-gray-200">
                        <thead class="text-xs text-gray-700 uppercase bg-gray-50 dark:bg-gray-700 dark:text-gray-400">
                            @if (isset($anteprima[0]))
                                <tr>
                                    @foreach ($anteprima[0] as $key => $val)
                                        <th scope="col" class="px-4 py-2 border">{{ 'Col ' . ($key + 1) }}</th>
                                    @endforeach
                                </tr>
                            @endif
                        </thead>
                        <tbody>
                            @foreach ($anteprima as $row)
                                <tr class="bg-white border-b dark:bg-gray-800 dark:border-gray-700">
                                    @foreach ($row as $cell)
                                        <td class="px-4 py-2 border">{{ $cell }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        </x-filament::card>
    @endif
</x-filament::page>
