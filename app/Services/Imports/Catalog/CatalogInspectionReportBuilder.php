<?php

declare(strict_types=1);

namespace App\Services\Imports\Catalog;

use App\Services\Imports\Catalog\Data\CatalogImportRow;
use App\Services\Imports\Catalog\Data\CatalogParseResult;

final class CatalogInspectionReportBuilder
{
    /**
     * @param  array<string, mixed>  $validation
     * @return array<string, mixed>
     */
    public function build(CatalogParseResult $parsed, array $validation): array
    {
        $validRows = array_values(array_filter(
            $parsed->rows,
            static fn (CatalogImportRow $row): bool => $row->isValid()
        ));
        $invalidRows = array_values(array_filter(
            $parsed->rows,
            static fn (CatalogImportRow $row): bool => ! $row->isValid()
        ));
        $imagesFound = array_sum(array_map(
            static fn (array $sheet): int => (int) ($sheet['images_count'] ?? 0),
            $parsed->sheetStructures
        ));
        $imagesAssociated = count(array_filter(
            $parsed->rows,
            static fn (CatalogImportRow $row): bool => $row->image !== null
        ));
        $duplicateSupplierCodes = $validation['duplicate_supplier_codes'] ?? [];
        $duplicateCustomerCodes = $validation['duplicate_customer_article_codes'] ?? [];
        $allDuplicateCodes = array_values(array_unique([
            ...array_column($duplicateSupplierCodes, 'code'),
            ...array_column($duplicateCustomerCodes, 'code'),
        ]));

        return [
            'mode' => 'read_only_diagnostic',
            'source' => [
                'supplier' => $parsed->supplier,
                'profile' => $parsed->profile,
                'file' => $parsed->sourceFile,
                'file_hash_sha256' => $parsed->fileHash,
            ],
            'summary' => [
                'rows_read' => $parsed->rowsRead,
                'rows_normalized' => count($parsed->rows),
                'rows_valid' => count($validRows),
                'rows_ignored' => count($parsed->ignoredRows),
                'rows_with_warnings' => count($validation['rows_with_warnings'] ?? []),
                'rows_invalid' => count($invalidRows),
                'rows_orderable' => count(array_filter(
                    $parsed->rows,
                    static fn (CatalogImportRow $row): bool => $row->orderable
                )),
                'rows_non_orderable' => count(array_filter(
                    $parsed->rows,
                    static fn (CatalogImportRow $row): bool => ! $row->orderable
                )),
                'duplicate_codes' => count($allDuplicateCodes),
                'duplicate_customer_article_codes' => count($duplicateCustomerCodes),
                'duplicate_supplier_codes' => count($duplicateSupplierCodes),
                'possible_code_changes' => count($validation['possible_code_changes'] ?? []),
                'conflicts' => count($validation['conflicts'] ?? []),
                'images_found' => $imagesFound,
                'images_associated' => $imagesAssociated,
                'images_unassociated' => count($parsed->unassociatedImages),
            ],
            'columns' => [
                'recognized' => $parsed->recognizedColumns,
                'unrecognized' => $parsed->unrecognizedColumns,
                'ignored' => $parsed->ignoredColumns,
            ],
            'sheet_structures' => $parsed->sheetStructures,
            'units' => [
                'sales' => $this->valueCounts($parsed->rows, 'salesUnit'),
                'price' => $this->valueCounts($parsed->rows, 'sourcePriceUnit'),
            ],
            'packaging_combinations' => $this->packagingCounts($parsed->rows),
            'diagnostics' => [
                'duplicate_codes' => $allDuplicateCodes,
                'duplicate_supplier_codes' => $duplicateSupplierCodes,
                'duplicate_customer_article_codes' => $duplicateCustomerCodes,
                'possible_code_changes' => $validation['possible_code_changes'] ?? [],
                'conflicts' => $validation['conflicts'] ?? [],
                'unassociated_images' => $parsed->unassociatedImages,
                'non_orderable_rows' => array_map(
                    static fn (CatalogImportRow $row): array => [
                        'source_sheet' => $row->sourceSheet,
                        'source_row' => $row->sourceRow,
                        'supplier_code' => $row->supplierCode,
                        'reason' => $row->nonOrderableReason,
                    ],
                    array_values(array_filter(
                        $parsed->rows,
                        static fn (CatalogImportRow $row): bool => ! $row->orderable
                    ))
                ),
                'invalid_rows' => array_map(
                    static fn (CatalogImportRow $row): array => [
                        'source_sheet' => $row->sourceSheet,
                        'source_row' => $row->sourceRow,
                        'supplier_code' => $row->supplierCode,
                        'customer_article_code' => $row->customerArticleCode,
                        'errors' => $row->errors,
                    ],
                    $invalidRows
                ),
                'ignored_rows' => $parsed->ignoredRows,
            ],
            'parser_messages' => [
                'warnings' => $parsed->warnings,
                'errors' => $parsed->errors,
            ],
            'rows' => array_map(
                static fn (CatalogImportRow $row): array => $row->toArray(),
                $parsed->rows
            ),
        ];
    }

    /**
     * @param  array<int, CatalogImportRow>  $rows
     * @return array<string, int>
     */
    private function valueCounts(array $rows, string $property): array
    {
        $counts = [];

        foreach ($rows as $row) {
            $value = $row->{$property};
            $key = $value ?? '[missing]';
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        arsort($counts);

        return $counts;
    }

    /**
     * @param  array<int, CatalogImportRow>  $rows
     * @return array<string, int>
     */
    private function packagingCounts(array $rows): array
    {
        $counts = [];

        foreach ($rows as $row) {
            foreach ($row->packagings as $packaging) {
                $key = sprintf(
                    '%s -> %s x %s%s',
                    $packaging->fromUnit,
                    $packaging->toUnit,
                    rtrim(rtrim(number_format($packaging->multiplier, 5, '.', ''), '0'), '.'),
                    $packaging->mandatory ? ' [mandatory]' : ''
                );
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }

        arsort($counts);

        return $counts;
    }
}
