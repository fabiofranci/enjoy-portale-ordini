<?php

declare(strict_types=1);

namespace App\Services\Imports\Catalog\Exceptions;

use RuntimeException;

final class CatalogImportConflictException extends RuntimeException
{
    /**
     * @param  array<int, array<string, mixed>>  $conflicts
     */
    public function __construct(public readonly array $conflicts)
    {
        parent::__construct(
            'Importazione interrotta: uno o piu codici fornitore hanno dati incompatibili.'
        );
    }
}
