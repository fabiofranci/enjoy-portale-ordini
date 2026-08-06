<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <title>Ordine cliente - richiesta preventivo #{{ $ordine->id }}</title>
    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 12px;
            color: #111827;
            margin: 24px;
        }

        h1 {
            font-size: 22px;
            margin: 0 0 8px;
        }

        h2 {
            font-size: 14px;
            margin: 20px 0 8px;
        }

        .muted {
            color: #6b7280;
        }

        .meta-table,
        .items-table {
            width: 100%;
            border-collapse: collapse;
        }

        .meta-table td {
            padding: 6px 0;
            vertical-align: top;
        }

        .meta-label {
            width: 180px;
            font-weight: bold;
        }

        .items-table th,
        .items-table td {
            border: 1px solid #d1d5db;
            padding: 8px;
            text-align: left;
        }

        .items-table th {
            background: #f3f4f6;
            font-weight: bold;
        }

        .text-right {
            text-align: right;
        }
    </style>
</head>
<body>
    <h1>Ordine cliente - richiesta preventivo</h1>
    <div class="muted">Documento generato dal Portale Clienti Enjoy</div>
    <p><strong>I prezzi sono concordati e IVA inclusa. La richiesta costituisce un ordine impegnativo del cliente.</strong></p>

    <h2>Dati ordine</h2>
    <table class="meta-table">
        <tr>
            <td class="meta-label">Ordine locale</td>
            <td>#{{ $ordine->id }}</td>
        </tr>
        <tr>
            <td class="meta-label">Data invio</td>
            <td>{{ ($ordine->data_ordine ?? $ordine->created_at)?->format('d/m/Y H:i') ?? now()->format('d/m/Y H:i') }}</td>
        </tr>
        <tr>
            <td class="meta-label">Priorita</td>
            <td><strong>{{ $ordine->prioritaLabel() }}</strong></td>
        </tr>
        <tr>
            <td class="meta-label">Conferma ordine</td>
            <td>{{ $ordine->riferimento_cliente ?: '-' }}</td>
        </tr>
        <tr>
            <td class="meta-label">Cliente</td>
            <td>{{ $ordine->cliente_nome ?? '-' }}</td>
        </tr>
        <tr>
            <td class="meta-label">Partita IVA</td>
            <td>{{ $ordine->cliente_partita_iva ?? '-' }}</td>
        </tr>
        <tr>
            <td class="meta-label">Contatto</td>
            <td>{{ $ordine->inviato_da_nome ?? '-' }}</td>
        </tr>
        <tr>
            <td class="meta-label">Email</td>
            <td>{{ $ordine->inviato_da_email ?? '-' }}</td>
        </tr>
        <tr>
            <td class="meta-label">Indirizzo di destinazione</td>
            <td>{{ $ordine->indirizzo_destinazione ?? '-' }}</td>
        </tr>
        <tr>
            <td class="meta-label">Riferimento in loco</td>
            <td>{{ $ordine->riferimento_richiedente ?? '-' }}</td>
        </tr>
        <tr>
            <td class="meta-label">Orari di consegna</td>
            <td>{{ $ordine->orari_consegna ?? '-' }}</td>
        </tr>
        <tr>
            <td class="meta-label">Telefono</td>
            <td>{{ $ordine->user?->cliente?->telefono ?? '-' }}</td>
        </tr>
        <tr>
            <td class="meta-label">Centro di costo</td>
            <td>{{ $ordine->centro_costo_nome ?? '-' }}</td>
        </tr>
        <tr>
            <td class="meta-label">Fornitore</td>
            <td>{{ $ordine->fornitore_code ?? '-' }}</td>
        </tr>
        @if ($ordine->note)
            <tr>
                <td class="meta-label">Note</td>
                <td>{{ $ordine->note }}</td>
            </tr>
        @endif
    </table>

    <h2>Righe richiesta</h2>
    <table class="items-table">
        <thead>
            <tr>
                <th style="width: 15%;">Codice</th>
                <th>Articolo</th>
                <th style="width: 10%;">UDM</th>
                <th style="width: 10%;" class="text-right">Quantita</th>
                <th style="width: 15%;" class="text-right">Prezzo IVA incl.</th>
                <th style="width: 15%;" class="text-right">Totale</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($ordine->items as $item)
                <tr>
                    <td>{{ $item->supplier_code ?? '-' }}</td>
                    <td>{{ $item->descrizione ?? 'Articolo non disponibile' }}</td>
                    <td>{{ $item->unita ?? 'NR' }}</td>
                    <td class="text-right">{{ (int) $item->quantita }}</td>
                    <td class="text-right">EUR {{ number_format((float) $item->prezzo_unitario_lordo, 2, ',', '.') }}</td>
                    <td class="text-right">EUR {{ number_format((float) $item->totale_riga_lordo, 2, ',', '.') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <p style="margin-top: 20px; text-align: right; font-size: 14px;">
        <strong>Totale IVA inclusa: EUR {{ number_format((float) $ordine->totale_lordo, 2, ',', '.') }}</strong>
    </p>
</body>
</html>
