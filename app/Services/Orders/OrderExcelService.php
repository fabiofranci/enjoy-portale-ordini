<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Models\Ordine;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

final class OrderExcelService
{
    /** @return array{path:string, filename:string, content:string} */
    public function generate(Ordine $ordine): array
    {
        $ordine->loadMissing(['user.cliente', 'centroCosto', 'fornitore', 'items']);
        $fileName = $this->buildFileName($ordine);
        $path = $this->buildStoragePath($ordine, $fileName);
        $content = $this->render($ordine);

        if (! Storage::disk('local')->put($path, $content)) {
            throw new RuntimeException('Impossibile salvare il documento Excel.');
        }

        $ordine->forceFill(['xlsx_path' => $path])->save();

        return [
            'path' => $path,
            'filename' => $fileName,
            'content' => $content,
        ];
    }

    private function render(Ordine $ordine): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Ordine');
        $sheet->setCellValue('A1', 'Ordine cliente');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);

        $metadata = [
            ['Ordine interno', $ordine->id],
            ['Numero ordine cliente', $ordine->riferimento_cliente],
            ['Data ordine', ($ordine->data_ordine ?? $ordine->created_at)?->format('d/m/Y H:i')],
            ['Priorita', $ordine->prioritaLabel()],
            ['Cliente', $ordine->cliente_nome],
            ['Centro di costo', $ordine->centro_costo_nome],
            ['Fornitore', $ordine->fornitore_code],
            ['Inviato da', $ordine->inviato_da_nome],
            ['Email mittente', $ordine->inviato_da_email],
            ['Indirizzo di destinazione', $ordine->indirizzo_destinazione],
            ['Riferimento in loco', $ordine->riferimento_richiedente],
            ['Orari di consegna', $ordine->orari_consegna],
            ['Note', $ordine->note],
        ];
        $metadataRow = 3;
        foreach ($metadata as [$label, $value]) {
            $sheet->setCellValue("A{$metadataRow}", $label);
            $this->setText($sheet, "B{$metadataRow}", $value);
            $metadataRow++;
        }
        $sheet->getStyle('A3:A15')->getFont()->setBold(true);

        $headerRow = 17;
        $sheet->fromArray([
            'Codice',
            'Articolo',
            'UDM',
            'Quantita',
            'Prezzo IVA incl.',
            'Totale IVA incl.',
        ], null, "A{$headerRow}");
        $sheet->getStyle("A{$headerRow}:F{$headerRow}")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '374151']],
        ]);

        $rowNumber = $headerRow + 1;
        foreach ($ordine->items as $item) {
            $this->setText($sheet, "A{$rowNumber}", $item->supplier_code);
            $this->setText($sheet, "B{$rowNumber}", $item->descrizione);
            $this->setText($sheet, "C{$rowNumber}", $item->unita);
            $sheet->setCellValue("D{$rowNumber}", (int) $item->quantita);
            $sheet->setCellValue("E{$rowNumber}", (float) $item->prezzo_unitario_lordo);
            $sheet->setCellValue("F{$rowNumber}", (float) $item->totale_riga_lordo);
            $rowNumber++;
        }

        $sheet->setCellValue("E{$rowNumber}", 'Totale IVA inclusa');
        $sheet->setCellValue("F{$rowNumber}", (float) $ordine->totale_lordo);
        $sheet->getStyle("E{$rowNumber}:F{$rowNumber}")->getFont()->setBold(true);
        $sheet->getStyle('E18:F'.$rowNumber)->getNumberFormat()->setFormatCode('#,##0.00 [$EUR]');

        foreach (range('A', 'F') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'order_xlsx_');
        if ($temporaryPath === false) {
            throw new RuntimeException('Impossibile creare il documento Excel.');
        }

        try {
            (new Xlsx($spreadsheet))->save($temporaryPath);
            $content = file_get_contents($temporaryPath);

            if ($content === false) {
                throw new RuntimeException('Impossibile leggere il documento Excel generato.');
            }

            return $content;
        } finally {
            $spreadsheet->disconnectWorksheets();
            @unlink($temporaryPath);
        }
    }

    private function buildFileName(Ordine $ordine): string
    {
        $reference = trim((string) ($ordine->riferimento_cliente ?? ''));
        $suffix = $reference !== '' ? Str::slug($reference) : 'ordine-'.$ordine->id;

        return sprintf('ordine-%d-%s.xlsx', $ordine->id, $suffix);
    }

    private function buildStoragePath(Ordine $ordine, string $fileName): string
    {
        $datePath = ($ordine->data_ordine ?? $ordine->created_at)?->format('Y/m') ?? now()->format('Y/m');

        return sprintf('ordini/documenti/%s/%s', $datePath, $fileName);
    }

    private function setText(Worksheet $sheet, string $cell, mixed $value): void
    {
        $sheet->setCellValueExplicit($cell, (string) ($value ?? ''), DataType::TYPE_STRING);
    }
}
