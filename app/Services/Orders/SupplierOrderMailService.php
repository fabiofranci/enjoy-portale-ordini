<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Mail\OrderQuoteRequestMail;
use App\Models\Ordine;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

final class SupplierOrderMailService
{
    public function send(Ordine $ordine, string $pdfPath, string $pdfFileName): void
    {
        if ($ordine->email_sent_at !== null) {
            return;
        }

        $supplierEmail = $this->validEmail($ordine->fornitore?->email);
        $administrationEmail = $this->validEmail(config('services.orders.administration_email'));
        $recipients = array_values(array_unique(array_filter([
            $supplierEmail,
            $administrationEmail,
        ])));

        $ordine->forceFill(['email_recipients' => $recipients])->save();

        if ($recipients === []) {
            Log::warning('Order persisted without configured email recipients', [
                'ordine_id' => $ordine->getKey(),
                'supplier_code' => $ordine->fornitore?->code,
            ]);

            return;
        }

        $to = $supplierEmail ?? $administrationEmail;
        $pendingMail = Mail::to($to);

        if ($supplierEmail !== null && $administrationEmail !== null && $administrationEmail !== $supplierEmail) {
            $pendingMail->cc($administrationEmail);
        }

        $pendingMail->send(new OrderQuoteRequestMail($ordine, $pdfPath, $pdfFileName));

        $complete = $supplierEmail !== null && $administrationEmail !== null;
        $ordine->forceFill([
            'email_stato' => $complete ? 'inviata' : 'parziale',
            'email_sent_at' => now(),
        ])->save();
    }

    private function validEmail(mixed $value): ?string
    {
        $email = trim((string) $value);

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    }
}
