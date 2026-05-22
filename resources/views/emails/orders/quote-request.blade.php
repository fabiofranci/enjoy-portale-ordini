<p>Buongiorno,</p>

<p>in allegato trovate la richiesta di preventivo generata dal Portale Clienti Enjoy.</p>

<p>
    Ordine locale: #{{ $ordine->id }}<br>
    Conferma ordine: {{ $ordine->riferimento_cliente ?: '-' }}<br>
    Cliente: {{ $ordine->user?->cliente?->nome ?? $ordine->user?->name ?? '-' }}
</p>

<p>Il PDF allegato non contiene prezzi.</p>

<p>Portale Clienti Enjoy</p>
