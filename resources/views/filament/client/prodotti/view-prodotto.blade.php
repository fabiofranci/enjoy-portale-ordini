<x-filament::page>
    <div class="flex flex-col lg:flex-row gap-8 p-6">
        {{-- Immagine prodotto --}}
        <div class="w-full lg:w-1/3 flex justify-center">
            @if ($record->immagine)
                <img src="{{ asset('storage/' . $record->immagine) }}"
                     alt="{{ $record->nome }}"
                     class="rounded-xl shadow-lg max-h-96 object-contain">
            @else
                <div class="bg-gray-100 rounded-xl w-64 h-64 flex items-center justify-center text-gray-400">
                    Nessuna immagine
                </div>
            @endif
        </div>

        {{-- Dettagli prodotto --}}
        <div class="flex-1 space-y-4">
            <h2 class="text-3xl font-bold text-gray-800">{{ $record->nome }}</h2>

            <div class="text-gray-600 text-sm">
                <p><strong>Codice:</strong> {{ $record->codice }}</p>
                @if($record->categoria)
                    <p><strong>Categoria:</strong> {{ $record->categoria->nome }}</p>
                @endif
            </div>

@if(!is_null($prezzoBase))
<div
    x-data="{
        baseUnit: '{{ $record->unita_misura }}',
        basePrice: {{ $prezzoBase }},
        selectedUnit: '{{ $record->unita_misura }}',
        packagings: {
            @foreach($record->packagings as $p)
                '{{ $p->from_unit }}': {{ (float) $p->multiplier }},
            @endforeach
        },
        price() {
            if (this.selectedUnit === this.baseUnit) {
                return this.basePrice;
            }
            return this.basePrice * this.packagings[this.selectedUnit];
        }
    }"
    class="space-y-4"
>
    {{-- Unità --}}
    <div class="flex gap-6">
        <label class="flex items-center gap-2">
            <input type="radio" x-model="selectedUnit" value="{{ $record->unita_misura }}">
            {{ $record->unita_misura }}
        </label>

        @foreach($record->packagings as $p)
            <label class="flex items-center gap-2">
                <input type="radio" x-model="selectedUnit" value="{{ $p->from_unit }}">
                {{ $p->from_unit }}
                <span class="text-xs text-gray-500">
                    (1 {{ $p->from_unit }} = {{ $p->multiplier }} {{ $p->to_unit }})
                </span>
            </label>
        @endforeach
    </div>

    {{-- Prezzo --}}
    <p class="text-2xl font-semibold text-green-700">
        € <span x-text="price().toFixed(2).replace('.', ',')"></span>
    </p>
</div>
@else
<p class="text-red-600 font-semibold">
    Prezzo non disponibile
</p>
@endif


            <p class="text-gray-700 leading-relaxed">
                {!! nl2br(e($record->descrizione)) !!}
            </p>

            @if($record->pdf_sicurezza)
                <a href="{{ asset('storage/' . $record->pdf_sicurezza) }}"
                   target="_blank"
                   class="inline-flex items-center gap-2 text-blue-600 hover:text-blue-800 mt-2">
                    <x-heroicon-o-document-text class="w-5 h-5"/>
                    Scheda di sicurezza
                </a>
            @endif

        </div>
    </div>
</x-filament::page>
