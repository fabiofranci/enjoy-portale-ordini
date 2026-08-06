<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Models\Ordine;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use RuntimeException;

final class OrderQuotePdfService
{
    /**
     * @return array{
     *     path:string,
     *     filename:string,
     *     content:string
     * }
     */
    public function generate(Ordine $ordine): array
    {
        $ordine->loadMissing(['user.cliente', 'centroCosto', 'fornitore', 'items']);

        $fileName = $this->buildFileName($ordine);
        $path = $this->buildStoragePath($ordine, $fileName);
        $content = $this->renderPdf($ordine);

        if (! Storage::disk('local')->put($path, $content)) {
            throw new RuntimeException('Impossibile salvare il documento PDF.');
        }

        if (Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }

        $ordine->forceFill([
            'pdf_path' => $path,
        ])->save();

        Log::info('Order quote request PDF generated', [
            'ordine_id' => $ordine->id,
            'path' => $path,
        ]);

        return [
            'path' => $path,
            'filename' => $fileName,
            'content' => $content,
        ];
    }

    private function renderPdf(Ordine $ordine): string
    {
        $html = View::make('pdf.orders.quote-request', [
            'ordine' => $ordine,
        ])->render();

        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('a4');
        $dompdf->render();

        return $dompdf->output();
    }

    private function buildFileName(Ordine $ordine): string
    {
        $reference = trim((string) ($ordine->riferimento_cliente ?? ''));
        $suffix = $reference !== ''
            ? Str::slug($reference)
            : 'ordine-'.$ordine->id;

        return sprintf('ordine-%d-%s.pdf', $ordine->id, $suffix);
    }

    private function buildStoragePath(Ordine $ordine, string $fileName): string
    {
        $datePath = $ordine->created_at?->format('Y/m') ?? now()->format('Y/m');

        return sprintf('ordini/documenti/%s/%s', $datePath, $fileName);
    }
}
