<?php

declare(strict_types=1);

namespace App\Services\Catalog\Exceptions;

use DomainException;

final class ListinoAssignmentException extends DomainException
{
    /**
     * @param  array<int, string>  $referenceCodes
     */
    public function __construct(
        string $message,
        public readonly array $referenceCodes = [],
    ) {
        parent::__construct($message);
    }
}
