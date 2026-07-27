<?php

declare(strict_types=1);

namespace App\Services\Imports\Catalog\Data;

final readonly class CatalogParseResult
{
    /**
     * @param  array<int, CatalogImportRow>  $rows
     * @param  array<int, array<string, mixed>>  $ignoredRows
     * @param  array<int, array<string, mixed>>  $sheetStructures
     * @param  array<int, string>  $recognizedColumns
     * @param  array<int, string>  $unrecognizedColumns
     * @param  array<int, string>  $ignoredColumns
     * @param  array<int, array<string, mixed>>  $unassociatedImages
     * @param  array<int, string>  $warnings
     * @param  array<int, string>  $errors
     */
    public function __construct(
        public string $supplier,
        public ?string $profile,
        public string $sourceFile,
        public string $fileHash,
        public int $rowsRead,
        public array $rows,
        public array $ignoredRows,
        public array $sheetStructures,
        public array $recognizedColumns,
        public array $unrecognizedColumns,
        public array $ignoredColumns,
        public array $unassociatedImages,
        public array $warnings = [],
        public array $errors = [],
    ) {}
}
