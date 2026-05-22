@php
    $prezzoBase = $record->getPrezzoAttivo();
    $hasPrice = $prezzoBase !== null;
@endphp

<div
    x-data="{
        baseUnit: '{{ $record->unita_misura }}',
        basePrice: {{ $hasPrice ? (float) $prezzoBase : 'null' }},
        selectedUnit: '{{ $record->unita_misura }}',
        packagings: {
            @foreach($record->packagings as $p)
                '{{ $p->from_unit }}': {{ (float) $p->multiplier }},
            @endforeach
        },
        price() {
            if (this.basePrice === null) {
                return 0;
            }
            if (this.selectedUnit === this.baseUnit) {
                return this.basePrice;
            }
            return this.basePrice * (this.packagings[this.selectedUnit] ?? 1);
        }
    }"
>
    <div>
        <label>
            <input type="radio" x-model="selectedUnit" value="{{ $record->unita_misura }}">
            {{ $record->unita_misura }}
        </label>
        @foreach($record->packagings as $p)
            <label>
                <input type="radio" x-model="selectedUnit" value="{{ $p->from_unit }}">
                {{ $p->from_unit }}
            </label>
        @endforeach
    </div>

    @if($record->packagings->isNotEmpty())
        <div>
            @foreach($record->packagings as $p)
                <x-filament::badge>
                    1 {{ $p->from_unit }} = {{ $p->multiplier }} {{ $p->to_unit }}
                </x-filament::badge>
            @endforeach
        </div>
    @endif

    <div>
        @if ($hasPrice)
            € <span x-text="price().toFixed(2).replace('.', ',')"></span>
        @else
            <x-filament::badge color="danger">Prezzo non disponibile</x-filament::badge>
        @endif
    </div>

    <form method="GET" action="{{ route('filament.clienti.pages.carrello.add', ['prodotto' => $record->id]) }}">
        <input type="hidden" name="unita" x-bind:value="selectedUnit">
        <x-filament::button type="submit" size="sm" icon="heroicon-o-shopping-cart">
            Aggiungi
        </x-filament::button>
    </form>
</div>
