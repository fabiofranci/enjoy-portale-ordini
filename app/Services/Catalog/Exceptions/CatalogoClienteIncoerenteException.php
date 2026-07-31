<?php

declare(strict_types=1);

namespace App\Services\Catalog\Exceptions;

use DomainException;

final class CatalogoClienteIncoerenteException extends DomainException
{
    /**
     * @param  array<int, string>  $supplierCodes
     */
    public function __construct(
        string $message,
        public readonly array $supplierCodes = [],
    ) {
        parent::__construct($message);
    }
}
