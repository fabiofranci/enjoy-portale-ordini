<?php

declare(strict_types=1);

namespace App\Services\Imports\Catalog\Data;

final readonly class CatalogInspectionResult
{
    /**
     * @param  array<string, mixed>  $report
     */
    public function __construct(
        public CatalogParseResult $parsed,
        public array $report,
    ) {}
}
