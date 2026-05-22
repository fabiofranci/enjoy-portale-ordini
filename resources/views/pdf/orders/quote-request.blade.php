<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <title>Richiesta preventivo ordine #{{ $ordine->id }}</title>
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
    <h1>Richiesta preventivo</h1>
    <div class="muted">Documento generato dal Portale Clienti Enjoy</div>

    <h2>Dati ordine</h2>
    <table class="meta-table">
        <tr>
            <td class="meta-label">Ordine locale</td>
            <td>#{{ $ordine->id }}</td>
        </tr>
        <tr>
            <td class="meta-label">Data invio</td>
            <td>{{ $ordine->created_at?->format('d/m/Y H:i') ?? now()->format('d/m/Y H:i') }}</td>
        </tr>
        <tr>
            <td class="meta-label">Conferma ordine</td>
            <td>{{ $ordine->riferimento_cliente ?: '-' }}</td>
        </tr>
        <tr>
            <td class="meta-label">Cliente</td>
            <td>{{ $ordine->user?->cliente?->nome ?? $ordine->user?->name ?? '-' }}</td>
        </tr>
        <tr>
            <td class="meta-label">Partita IVA</td>
            <td>{{ $ordine->user?->cliente?->partita_iva ?? '-' }}</td>
        </tr>
        <tr>
            <td class="meta-label">Contatto</td>
            <td>{{ $ordine->user?->name ?? '-' }}</td>
        </tr>
        <tr>
            <td class="meta-label">Email</td>
            <td>{{ $ordine->user?->email ?? $ordine->user?->cliente?->email ?? '-' }}</td>
        </tr>
        <tr>
            <td class="meta-label">Telefono</td>
            <td>{{ $ordine->user?->cliente?->telefono ?? '-' }}</td>
        </tr>
        <tr>
            <td class="meta-label">Centro di costo</td>
            <td>{{ $ordine->centroCosto?->nome ?? '-' }}</td>
        </tr>
    </table>

    <h2>Righe richiesta</h2>
    <table class="items-table">
        <thead>
            <tr>
                <th style="width: 18%;">Codice</th>
                <th>Prodotto</th>
                <th style="width: 14%;">UDM</th>
                <th style="width: 14%;" class="text-right">Quantita</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($ordine->items as $item)
                <tr>
                    <td>{{ $item->prodotto?->codice ?? '-' }}</td>
                    <td>{{ $item->prodotto?->nome ?? 'Prodotto non disponibile' }}</td>
                    <td>{{ $item->unita ?? $item->prodotto?->unita_misura ?? 'NR' }}</td>
                    <td class="text-right">{{ (int) $item->quantita }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <p class="muted" style="margin-top: 20px;">
        Documento privo di prezzi come da flusso di richiesta preventivo.
    </p>
</body>
</html>
