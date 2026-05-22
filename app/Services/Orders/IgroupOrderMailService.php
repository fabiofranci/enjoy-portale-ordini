<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Mail\OrderQuoteRequestMail;
use App\Models\Ordine;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

final class IgroupOrderMailService
{
    public function ensureConfigured(): void
    {
        [$to] = $this->resolveRecipients();

        if ($to === []) {
            throw ValidationException::withMessages([
                'igroup' => 'Configurare IGROUP_QUOTE_REQUEST_TO prima di inviare ordini.',
            ]);
        }
    }

    public function send(Ordine $ordine, string $pdfPath, string $pdfFileName): void
    {
        if ($ordine->igroup_sent_at !== null) {
            Log::info('Skipping iGroup order mail because it was already sent', [
                'ordine_id' => $ordine->id,
                'igroup_sent_at' => $ordine->igroup_sent_at?->format('Y-m-d H:i:s'),
            ]);

            return;
        }

        [$to, $cc] = $this->resolveRecipients();

        Mail::to($to)
            ->cc($cc)
            ->send(new OrderQuoteRequestMail($ordine, $pdfPath, $pdfFileName));

        $ordine->forceFill([
            'igroup_sent_at' => now(),
        ])->save();

        Log::info('Order quote request mail sent to iGroup', [
            'ordine_id' => $ordine->id,
            'to' => $to,
            'cc' => $cc,
        ]);
    }

    /**
     * @return array{0:array<int, string>,1:array<int, string>}
     */
    private function resolveRecipients(): array
    {
        $to = $this->normalizeRecipients(config('services.igroup.quote_request_to'));
        $cc = $this->normalizeRecipients(config('services.igroup.quote_request_cc'));

        return [$to, $cc];
    }

    /**
     * @return array<int, string>
     */
    private function normalizeRecipients(mixed $value): array
    {
        if (is_array($value)) {
            $rawRecipients = $value;
        } else {
            $rawRecipients = explode(',', (string) $value);
        }

        $recipients = [];

        foreach ($rawRecipients as $recipient) {
            $email = trim((string) $recipient);

            if ($email === '') {
                continue;
            }

            $recipients[] = $email;
        }

        return array_values(array_unique($recipients));
    }
}
