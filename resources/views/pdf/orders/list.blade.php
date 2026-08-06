<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Elenco ordini</title>
    <style>
        @page { margin: 24px; }
        body { font-family: "DejaVu Sans", sans-serif; color: #111827; font-size: 9px; }
        h1 { margin: 0 0 6px; font-size: 18px; }
        .meta { margin: 0 0 14px; color: #4b5563; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #d1d5db; padding: 5px; vertical-align: top; }
        th { background: #374151; color: #fff; text-align: left; }
        .number { text-align: right; white-space: nowrap; }
        .urgent { color: #b91c1c; font-weight: bold; }
        tfoot td { font-weight: bold; background: #f3f4f6; }
    </style>
</head>
<body>
    <h1>Elenco ordini</h1>
    <p class="meta">
        @if ($from || $to)
            Intervallo: dal {{ $from ?? 'inizio' }} al {{ $to ?? 'oggi' }}
        @else
            Tutte le date
        @endif
        &middot; Generato il {{ now()->format('d/m/Y H:i') }}
    </p>

    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Numero cliente</th>
                <th>Data</th>
                <th>Stato</th>
                <th>Priorita</th>
                <th>Cliente</th>
                <th>Centro di costo</th>
                <th>Fornitore</th>
                <th>Destinazione</th>
                <th class="number">Totale IVA incl.</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($orders as $order)
                <tr>
                    <td>{{ $order->id }}</td>
                    <td>{{ $order->riferimento_cliente ?? '-' }}</td>
                    <td>{{ ($order->data_ordine ?? $order->created_at)?->format('d/m/Y H:i') ?? '-' }}</td>
                    <td>{{ $order->statoLabel() }}</td>
                    <td @class(['urgent' => $order->priorita === \App\Models\Ordine::PRIORITY_URGENT])>
                        {{ $order->prioritaLabel() }}
                    </td>
                    <td>{{ $order->cliente_nome ?? '-' }}</td>
                    <td>{{ $order->centro_costo_nome ?? '-' }}</td>
                    <td>{{ $order->fornitore_code ?? '-' }}</td>
                    <td>{{ $order->indirizzo_destinazione ?? '-' }}</td>
                    <td class="number">&euro; {{ number_format((float) $order->totale_lordo, 2, ',', '.') }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="10">Nessun ordine nell'intervallo selezionato.</td>
                </tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr>
                <td colspan="9">Totale IVA inclusa</td>
                <td class="number">&euro; {{ number_format((float) $orders->sum('totale_lordo'), 2, ',', '.') }}</td>
            </tr>
        </tfoot>
    </table>
</body>
</html>
