<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Models\Ordine;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\View;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

final class OrderListExportService
{
    /** @return array{filename:string, content:string, mime:string} */
    public function export(
        Builder $query,
        string $format,
        ?string $from = null,
        ?string $to = null,
    ): array {
        /** @var Collection<int, Ordine> $orders */
        $orders = (clone $query)->get();

        return match (strtolower($format)) {
            'pdf' => $this->pdf($orders, $from, $to),
            'xlsx' => $this->xlsx($orders, $from, $to),
            default => throw new InvalidArgumentException('Formato export ordini non supportato.'),
        };
    }

    /**
     * @param  Collection<int, Ordine>  $orders
     * @return array{filename:string, content:string, mime:string}
     */
    private function pdf(Collection $orders, ?string $from, ?string $to): array
    {
        $html = View::make('pdf.orders.list', [
            'orders' => $orders,
            'from' => $from,
            'to' => $to,
        ])->render();
        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('a4', 'landscape');
        $dompdf->render();

        return [
            'filename' => $this->filename('pdf'),
            'content' => $dompdf->output(),
            'mime' => 'application/pdf',
        ];
    }

    /**
     * @param  Collection<int, Ordine>  $orders
     * @return array{filename:string, content:string, mime:string}
     */
    private function xlsx(Collection $orders, ?string $from, ?string $to): array
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Ordini');
        $sheet->setCellValue('A1', 'Elenco ordini');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
        $sheet->setCellValue('A2', 'Intervallo');
        $sheet->setCellValue('B2', $this->rangeLabel($from, $to));
        $sheet->fromArray([
            'ID interno',
            'Numero ordine cliente',
            'Data ordine',
            'Stato',
            'Priorita',
            'Cliente',
            'Centro di costo',
            'Fornitore',
            'Inviato da',
            'Destinazione',
            'Totale IVA inclusa',
        ], null, 'A4');
        $sheet->getStyle('A4:K4')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '374151']],
        ]);

        $row = 5;
        foreach ($orders as $order) {
            $sheet->setCellValue("A{$row}", $order->id);
            $this->setText($sheet, "B{$row}", $order->riferimento_cliente);
            $this->setText($sheet, "C{$row}", ($order->data_ordine ?? $order->created_at)?->format('d/m/Y H:i'));
            $this->setText($sheet, "D{$row}", $order->statoLabel());
            $this->setText($sheet, "E{$row}", $order->prioritaLabel());
            $this->setText($sheet, "F{$row}", $order->cliente_nome);
            $this->setText($sheet, "G{$row}", $order->centro_costo_nome);
            $this->setText($sheet, "H{$row}", $order->fornitore_code);
            $this->setText($sheet, "I{$row}", $order->inviato_da_nome);
            $this->setText($sheet, "J{$row}", $order->indirizzo_destinazione);
            $sheet->setCellValue("K{$row}", (float) $order->totale_lordo);
            $row++;
        }

        $sheet->setCellValue("J{$row}", 'Totale IVA inclusa');
        $sheet->setCellValue("K{$row}", (float) $orders->sum('totale_lordo'));
        $sheet->getStyle("J{$row}:K{$row}")->getFont()->setBold(true);
        $sheet->getStyle('K5:K'.$row)->getNumberFormat()->setFormatCode('#,##0.00 [$EUR]');

        foreach (range('A', 'K') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'orders_xlsx_');
        if ($temporaryPath === false) {
            throw new RuntimeException('Impossibile creare l export Excel degli ordini.');
        }

        try {
            (new Xlsx($spreadsheet))->save($temporaryPath);
            $content = file_get_contents($temporaryPath);

            if ($content === false) {
                throw new RuntimeException('Impossibile leggere l export Excel degli ordini.');
            }

            return [
                'filename' => $this->filename('xlsx'),
                'content' => $content,
                'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ];
        } finally {
            $spreadsheet->disconnectWorksheets();
            @unlink($temporaryPath);
        }
    }

    private function setText(Worksheet $sheet, string $cell, mixed $value): void
    {
        $sheet->setCellValueExplicit($cell, (string) ($value ?? ''), DataType::TYPE_STRING);
    }

    private function filename(string $extension): string
    {
        return 'ordini-'.now()->format('Ymd-His').'.'.$extension;
    }

    private function rangeLabel(?string $from, ?string $to): string
    {
        if ($from === null && $to === null) {
            return 'Tutte le date';
        }

        return sprintf('Dal %s al %s', $from ?? 'inizio', $to ?? 'oggi');
    }
}
