<?php

declare(strict_types=1);

namespace App\Services\Imports\Catalog;

use App\Services\Imports\Catalog\Data\CatalogImportRow;

final readonly class CatalogImportValidator
{
    public function __construct(private CatalogImportNormalizer $normalizer) {}

    /**
     * @param  array<int, CatalogImportRow>  $rows
     * @return array<string, mixed>
     */
    public function validate(array $rows): array
    {
        $supplierGroups = $this->groupByCode($rows, 'supplier');
        $customerGroups = $this->groupByCode($rows, 'customer');
        $supplierDuplicates = $this->duplicateDiagnostics($supplierGroups, 'supplier');
        $customerDuplicates = $this->duplicateDiagnostics($customerGroups, 'customer');
        $possibleChanges = [];
        $conflicts = [];
        $warningRows = [];
        $ambiguousCustomerCodes = [];

        foreach ($rows as $row) {
            if ($row->warnings !== []) {
                $warningRows[$this->rowKey($row)] = true;
            }

            if (
                $row->supplierCode === null
                || $row->customerArticleCode === null
                || $row->supplierCode === $row->customerArticleCode
            ) {
                continue;
            }

            $collisions = array_values(array_filter(
                $supplierGroups[$row->customerArticleCode] ?? [],
                static fn (CatalogImportRow $candidate): bool => $candidate->supplierCode !== $row->supplierCode
            ));
            $status = $collisions === [] ? 'unambiguous' : 'ambiguous';
            $diagnostic = [
                'source_row' => $row->sourceRow,
                'supplier_code' => $row->supplierCode,
                'customer_article_code' => $row->customerArticleCode,
                'status' => $status,
                'colliding_supplier_rows' => array_map(
                    static fn (CatalogImportRow $candidate): array => [
                        'source_row' => $candidate->sourceRow,
                        'supplier_code' => $candidate->supplierCode,
                        'customer_article_code' => $candidate->customerArticleCode,
                    ],
                    $collisions
                ),
            ];

            $possibleChanges[] = $diagnostic;
            $warningRows[$this->rowKey($row)] = true;

            if ($status === 'ambiguous') {
                $ambiguousCustomerCodes[$row->customerArticleCode] = true;
                $conflicts[] = ['type' => 'ambiguous_code_change'] + $diagnostic;
            }
        }

        foreach ($supplierDuplicates as $duplicate) {
            foreach ($duplicate['source_rows'] as $line) {
                $warningRows[$duplicate['source_sheet'].':'.$line] = true;
            }

            if ($duplicate['kind'] === 'conflicting') {
                $conflicts[] = ['type' => 'conflicting_supplier_code_duplicate'] + $duplicate;
            }
        }

        foreach ($customerDuplicates as $duplicate) {
            foreach ($duplicate['source_rows'] as $line) {
                $warningRows[$duplicate['source_sheet'].':'.$line] = true;
            }

            if (
                $duplicate['kind'] === 'conflicting'
                && ! isset($ambiguousCustomerCodes[$duplicate['code']])
            ) {
                $conflicts[] = ['type' => 'conflicting_customer_code_duplicate'] + $duplicate;
            }
        }

        return [
            'duplicate_supplier_codes' => $supplierDuplicates,
            'duplicate_customer_article_codes' => $customerDuplicates,
            'possible_code_changes' => $possibleChanges,
            'conflicts' => $conflicts,
            'rows_with_warnings' => array_keys($warningRows),
        ];
    }

    /**
     * @param  array<int, CatalogImportRow>  $rows
     * @return array<string, array<int, CatalogImportRow>>
     */
    private function groupByCode(array $rows, string $type): array
    {
        $groups = [];

        foreach ($rows as $row) {
            $code = $type === 'supplier' ? $row->supplierCode : $row->customerArticleCode;
            if ($code !== null) {
                $groups[$code][] = $row;
            }
        }

        return $groups;
    }

    /**
     * @param  array<string, array<int, CatalogImportRow>>  $groups
     * @return array<int, array<string, mixed>>
     */
    private function duplicateDiagnostics(array $groups, string $type): array
    {
        $diagnostics = [];

        foreach ($groups as $code => $rows) {
            if (count($rows) < 2) {
                continue;
            }

            $signatures = [];
            foreach ($rows as $row) {
                $signatures[] = $this->normalizer->stableHash(
                    $this->businessPayload($row, $type)
                );
            }

            $diagnostics[] = [
                'code' => $code,
                'kind' => count(array_unique($signatures)) === 1 ? 'identical' : 'conflicting',
                'occurrences' => count($rows),
                'source_sheet' => $rows[0]->sourceSheet,
                'source_rows' => array_map(
                    static fn (CatalogImportRow $row): int => $row->sourceRow,
                    $rows
                ),
                'supplier_codes' => array_values(array_unique(array_filter(
                    array_map(
                        static fn (CatalogImportRow $row): ?string => $row->supplierCode,
                        $rows
                    ),
                    static fn (?string $code): bool => $code !== null
                ))),
                'customer_article_codes' => array_values(array_unique(array_filter(
                    array_map(
                        static fn (CatalogImportRow $row): ?string => $row->customerArticleCode,
                        $rows
                    ),
                    static fn (?string $code): bool => $code !== null
                ))),
            ];
        }

        return $diagnostics;
    }

    /**
     * @return array<string, mixed>
     */
    private function businessPayload(CatalogImportRow $row, string $groupType): array
    {
        return [
            'supplier_code' => $groupType === 'supplier' ? null : $row->supplierCode,
            'customer_article_code' => $groupType === 'customer' ? null : $row->customerArticleCode,
            'description' => $row->description,
            'category' => $row->category,
            'sales_unit' => $row->salesUnit,
            'source_price' => $row->sourcePrice,
            'source_price_unit' => $row->sourcePriceUnit,
            'gross_price' => $row->grossPrice,
            'discount_percentage' => $row->discountPercentage,
            'vat_percentage' => $row->vatPercentage,
            'carton_price' => $row->cartonPrice,
            'orderable' => $row->orderable,
            'non_orderable_reason' => $row->nonOrderableReason,
            'packagings' => array_map(
                static fn ($packaging): array => $packaging->toArray(),
                $row->packagings
            ),
            'image_hash' => $row->image?->contentHash,
        ];
    }

    private function rowKey(CatalogImportRow $row): string
    {
        return $row->sourceSheet.':'.$row->sourceRow;
    }
}
