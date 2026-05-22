<x-filament-panels::page>
    <div class="p-4 text-gray-700">
        <h2 class="text-xl font-semibold mb-3">Ciao, {{ auth()->user()->name }} 👋</h2>
        <p>Benvenuto nel portale clienti Enjoy. Da qui puoi consultare il catalogo prodotti e inviare i tuoi ordini.</p>
    </div>

    <div class="p-4">
        <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Cerca articoli</h3>
            <p class="text-sm text-gray-600 mb-4">Inserisci nome, codice o categoria per trovare rapidamente i prodotti.</p>

            <form method="GET" action="{{ \App\Filament\Client\Resources\Prodotti\ProdottoResource::getUrl('index') }}" class="flex flex-col gap-3 sm:flex-row sm:items-center">
                <input
                    type="text"
                    name="tableSearch"
                    value="{{ request()->query('tableSearch') }}"
                    placeholder="Es. mozzarella, 12345, latticini"
                    class="w-full sm:flex-1 rounded-lg border-gray-300 focus:border-primary-500 focus:ring-primary-500"
                />
                <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">
                    Cerca
                </button>
            </form>
        </div>
    </div>
</x-filament-panels::page>
