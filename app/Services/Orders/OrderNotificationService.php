<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Models\Ordine;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

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
        $pdf = $this->pdfService->generate($ordine);
        $this->mailService->send($ordine, $pdf['path'], $pdf['filename'], $force);

        return $ordine->refresh();
    }
}
