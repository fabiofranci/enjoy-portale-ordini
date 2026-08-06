<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Models\Ordine;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final class OrderStatusService
{
    public function markAsFulfilled(Ordine $ordine, User $actor): Ordine
    {
        if (! $actor->hasRole('admin')) {
            throw new AuthorizationException('Solo un amministratore puo evadere un ordine.');
        }

        if (! $ordine->isEvaso()) {
            $ordine->update(['stato' => Ordine::STATUS_FULFILLED]);
        }

        return $ordine->refresh();
    }
}
