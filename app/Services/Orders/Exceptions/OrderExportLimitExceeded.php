<?php

declare(strict_types=1);

namespace App\Services\Orders\Exceptions;

use RuntimeException;

final class OrderExportLimitExceeded extends RuntimeException
{
    public static function forLimit(int $limit): self
    {
        return new self(sprintf(
            'L export supera il limite di %d ordini. Restringi l intervallo di date e riprova.',
            $limit,
        ));
    }
}
