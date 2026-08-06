<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Models\Ordine;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Throwable;

final readonly class OrderNotificationService
{
    public function __construct(
        private OrderQuotePdfService $pdfService,
        private SupplierOrderMailService $mailService,
    ) {}

    public function sendInitial(Ordine $ordine): Ordine
    {
        return $this->deliver($ordine, false);
    }

    public function resend(Ordine $ordine, User $actor): Ordine
    {
        if (! $actor->hasRole('admin')) {
            throw new AuthorizationException('Solo un amministratore puo reinviare un ordine.');
        }

        return $this->deliver($ordine, true);
    }

    private function deliver(Ordine $ordine, bool $force): Ordine
    {
        $ordine->loadMissing(['user.cliente', 'centroCosto', 'fornitore', 'items']);

        try {
            $pdf = $this->pdfService->generate($ordine);
        } catch (Throwable $exception) {
            $this->recordPdfFailure($ordine, $exception);

            throw $exception;
        }

        $this->mailService->send($ordine, $pdf['path'], $pdf['filename'], $force);

        return $ordine->refresh();
    }

    private function recordPdfFailure(Ordine $ordine, Throwable $exception): void
    {
        $message = trim($exception->getMessage());

        $ordine->forceFill([
            'email_stato' => 'errore',
            'email_attempts' => (int) $ordine->email_attempts + 1,
            'email_last_attempt_at' => now(),
            'email_last_error' => Str::limit(
                'Preparazione PDF: '.($message !== '' ? $message : $exception::class),
                2000,
                '',
            ),
        ])->save();
    }
}
