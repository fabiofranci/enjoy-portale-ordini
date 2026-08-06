<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Models\Ordine;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

final readonly class OrderDocumentService
{
    public function __construct(
        private OrderQuotePdfService $pdfService,
        private OrderExcelService $excelService,
    ) {}

    /** @return array{path:string, filename:string, content:string, mime:string} */
    public function document(Ordine $ordine, string $format): array
    {
        return match (strtolower($format)) {
            'pdf' => $this->pdf($ordine),
            'xlsx' => $this->xlsx($ordine),
            default => throw new InvalidArgumentException('Formato documento ordine non supportato.'),
        };
    }

    /** @return array{path:string, filename:string, content:string, mime:string} */
    private function pdf(Ordine $ordine): array
    {
        $path = trim((string) $ordine->pdf_path);

        if ($path !== '' && Storage::disk('local')->exists($path)) {
            return [
                'path' => $path,
                'filename' => basename($path),
                'content' => Storage::disk('local')->get($path),
                'mime' => 'application/pdf',
            ];
        }

        return $this->pdfService->generate($ordine) + [
            'mime' => 'application/pdf',
        ];
    }

    /** @return array{path:string, filename:string, content:string, mime:string} */
    private function xlsx(Ordine $ordine): array
    {
        $path = trim((string) $ordine->xlsx_path);

        if ($path !== '' && Storage::disk('local')->exists($path)) {
            return [
                'path' => $path,
                'filename' => basename($path),
                'content' => Storage::disk('local')->get($path),
                'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ];
        }

        return $this->excelService->generate($ordine) + [
            'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];
    }
}
