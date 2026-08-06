<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Mail\OrderQuoteRequestMail;
use App\Models\Ordine;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

final class SupplierOrderMailService
{
    public function send(
        Ordine $ordine,
        string $pdfPath,
        string $pdfFileName,
        bool $force = false,
    ): void {
        if ($ordine->email_sent_at !== null && ! $force) {
            return;
        }

        $supplierEmail = $this->validEmail($ordine->fornitore?->email);
        $administrationEmail = $this->validEmail(config('services.orders.administration_email'));
        $recipients = array_values(array_unique(array_filter([
            $supplierEmail,
            $administrationEmail,
        ])));

        $ordine->forceFill([
            'email_recipients' => $recipients,
            'email_attempts' => (int) $ordine->email_attempts + 1,
            'email_last_attempt_at' => now(),
            'email_last_error' => null,
        ])->save();

        if ($administrationEmail === null) {
            $message = 'Destinatario amministrativo ORDER_ADMINISTRATION_EMAIL non configurato.';
            $this->recordFailure($ordine, $message);

            Log::error('Order email not sent: administration recipient missing', [
                'ordine_id' => $ordine->getKey(),
                'supplier_code' => $ordine->fornitore?->code,
            ]);

            return;
        }

        try {
            $to = $supplierEmail ?? $administrationEmail;
            $pendingMail = Mail::to($to);

            if ($supplierEmail !== null && $administrationEmail !== $supplierEmail) {
                $pendingMail->cc($administrationEmail);
            }

            $pendingMail->send(new OrderQuoteRequestMail($ordine, $pdfPath, $pdfFileName));
        } catch (Throwable $exception) {
            $this->recordFailure($ordine, $exception->getMessage());

            throw $exception;
        }

        $complete = $supplierEmail !== null;
        $ordine->forceFill([
            'email_stato' => $complete ? 'inviata' : 'parziale',
            'email_sent_at' => now(),
            'email_last_error' => null,
        ])->save();
    }

    private function validEmail(mixed $value): ?string
    {
        $email = trim((string) $value);

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    }

    private function recordFailure(Ordine $ordine, string $message): void
    {
        $ordine->forceFill([
            'email_stato' => 'errore',
            'email_last_error' => Str::limit(trim($message), 2000, ''),
        ])->save();
    }
}
