<?php

declare(strict_types=1);

namespace App\Services\Imports\Catalog;

use App\Services\Imports\Catalog\Data\CatalogInspectionResult;
use InvalidArgumentException;

final readonly class CatalogImportInspector
{
    public function __construct(
        private IcaCatalogParser $icaParser,
        private IgroupCatalogParser $igroupParser,
        private CatalogImportValidator $validator,
        private CatalogInspectionReportBuilder $reportBuilder,
    ) {}

    public function inspect(string $supplier, string $filePath, ?string $profile = null): CatalogInspectionResult
    {
        $supplier = strtoupper(trim($supplier));

        $parsed = match ($supplier) {
            'ICA' => $this->icaParser->parse($filePath, $profile),
            'IGROUP' => $this->igroupParser->parse($filePath),
            default => throw new InvalidArgumentException(
                'Fornitore non supportato. Valori ammessi: ICA, IGROUP.'
            ),
        };

        $validation = $this->validator->validate($parsed->rows);

        return new CatalogInspectionResult(
            parsed: $parsed,
            report: $this->reportBuilder->build($parsed, $validation),
        );
    }
}
