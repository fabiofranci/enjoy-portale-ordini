<p>Buongiorno,</p>

<p>in allegato trovate l'ordine cliente, trasmesso come richiesta di preventivo.</p>

<p>
    Ordine locale: #{{ $ordine->id }}<br>
    Conferma ordine: {{ $ordine->riferimento_cliente ?: '-' }}<br>
    Cliente: {{ $ordine->cliente_nome ?? '-' }}<br>
    Centro di costo: {{ $ordine->centro_costo_nome ?? '-' }}<br>
    Fornitore: {{ $ordine->fornitore_code ?? '-' }}<br>
    Totale IVA inclusa: EUR {{ number_format((float) $ordine->totale_lordo, 2, ',', '.') }}
</p>

<p>I prezzi sono gia concordati e IVA inclusa. La richiesta costituisce un ordine impegnativo del cliente.</p>

<p>Portale Clienti Enjoy</p>
