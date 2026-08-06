<p>Buongiorno,</p>

<p>in allegato trovate l'ordine cliente, trasmesso come richiesta di preventivo.</p>

<p>
    Ordine locale: #{{ $ordine->id }}<br>
    Data ordine: {{ ($ordine->data_ordine ?? $ordine->created_at)?->format('d/m/Y H:i') }}<br>
    Conferma ordine: {{ $ordine->riferimento_cliente ?: '-' }}<br>
    Priorit&agrave;: <strong>{{ $ordine->prioritaLabel() }}</strong><br>
    Cliente: {{ $ordine->cliente_nome ?? '-' }}<br>
    Inviato da: {{ $ordine->inviato_da_nome ?? '-' }}{{ $ordine->inviato_da_email ? ' ('.$ordine->inviato_da_email.')' : '' }}<br>
    Centro di costo: {{ $ordine->centro_costo_nome ?? '-' }}<br>
    Indirizzo di destinazione: {{ $ordine->indirizzo_destinazione ?? '-' }}<br>
    Riferimento in loco: {{ $ordine->riferimento_richiedente ?? '-' }}<br>
    Orari di consegna: {{ $ordine->orari_consegna ?? '-' }}<br>
    Fornitore: {{ $ordine->fornitore_code ?? '-' }}<br>
    Totale IVA inclusa: EUR {{ number_format((float) $ordine->totale_lordo, 2, ',', '.') }}
</p>

@if ($ordine->note)
    <p><strong>Note:</strong> {{ $ordine->note }}</p>
@endif

<p>I prezzi sono gia concordati e IVA inclusa. La richiesta costituisce un ordine impegnativo del cliente.</p>

<p>Portale Clienti Enjoy</p>
