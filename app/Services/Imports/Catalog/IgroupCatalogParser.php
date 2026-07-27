<?php

declare(strict_types=1);

namespace App\Services\Imports\Catalog;

use App\Services\Imports\Catalog\Data\CatalogImportRow;
use App\Services\Imports\Catalog\Data\CatalogPackaging;
use App\Services\Imports\Catalog\Data\CatalogParseResult;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

final readonly class IgroupCatalogParser
{
    private const HEADER_ALIASES = [
        'id' => ['id'],
        'customer_id' => ['cod cliente'],
        'customer_name' => ['cliente'],
        'price_list_code' => ['cod listino'],
        'price_list_name' => ['listino'],
        'valid_from' => ['inizio validita'],
        'valid_to' => ['fine validita'],
        'supplier_code' => ['cod articolo'],
        'description' => ['articolo'],
        'price_unit' => ['umprezzo'],
        'gross_price' => ['prezzo'],
        'discount_1' => ['sconto1'],
        'discount_2' => ['sconto2'],
        'discount_3' => ['sconto3'],
        'discount_4' => ['sconto4'],
        'net_price' => ['prezzo netto'],
        'customer_article_code' => ['cod art cliente'],
        'sales_unit' => ['um'],
        'packaging' => ['imballo'],
        'subpackaging' => ['subimballo'],
        'conai' => ['imp conai'],
        'gross_weight' => ['peso lordo'],
        'net_weight' => ['peso netto'],
        'packaging_mandatory' => ['extimbtass'],
        'subpackaging_mandatory' => ['extsubimbtass'],
    ];

    private const SENSITIVE_COLUMNS = [
        'customer_id',
        'customer_name',
        'price_list_name',
    ];

    public function __construct(
        private SpreadsheetDocumentReader $reader,
        private CatalogImportNormalizer $normalizer,
    ) {}

    public function parse(string $filePath): CatalogParseResult
    {
        $document = $this->reader->read($filePath);
        $sheet = $document['sheets'][0] ?? null;

        if (! is_array($sheet)) {
            throw new InvalidArgumentException('Il file IGROUP non contiene fogli leggibili.');
        }

        $header = $this->detectHeader($sheet['rows']);
        if ($header === null) {
            throw new InvalidArgumentException(
                'Intestazione IGROUP non riconosciuta: servono Cod. Articolo, Articolo e Prezzo Netto.'
            );
        }

        $rows = [];
        $ignoredRows = [];
        $validityRanges = [];
        $codeRuleCounts = [
            'customer_code_blank' => 0,
            'customer_code_equal' => 0,
            'customer_code_different' => 0,
            'both_codes_missing' => 0,
        ];

        foreach ($sheet['rows'] as $line => $sourceRow) {
            if ($line <= $header['line']) {
                continue;
            }

            if ($this->rowIsEmpty($sourceRow)) {
                $ignoredRows[] = ['line' => $line, 'reason' => 'blank_row'];

                continue;
            }

            $raw = $this->rowByCanonicalColumn($sourceRow, $header['columns']);
            $supplierCode = $this->normalizer->code($raw['supplier_code'] ?? null);
            $explicitCustomerCode = $this->normalizer->code($raw['customer_article_code'] ?? null);
            $customerCode = $explicitCustomerCode ?? $supplierCode;
            $description = $this->normalizer->text($raw['description'] ?? null);
            $salesUnit = $this->normalizer->unit($raw['sales_unit'] ?? null);
            $priceUnit = $this->normalizer->unit($raw['price_unit'] ?? null);
            $grossPrice = $this->normalizer->decimal($raw['gross_price'] ?? null);
            $netPrice = $this->normalizer->decimal($raw['net_price'] ?? null);
            $discount = $this->normalizer->sequentialDiscount([
                $raw['discount_1'] ?? null,
                $raw['discount_2'] ?? null,
                $raw['discount_3'] ?? null,
                $raw['discount_4'] ?? null,
            ]);
            $packagingMultiplier = $this->normalizer->decimal($raw['packaging'] ?? null);
            $subpackagingMultiplier = $this->normalizer->decimal($raw['subpackaging'] ?? null);
            $packagingMandatory = $this->normalizer->decimal($raw['packaging_mandatory'] ?? null) === -1.0;
            $subpackagingMandatory = $this->normalizer->decimal($raw['subpackaging_mandatory'] ?? null) === -1.0;
            $packagings = [];
            $warnings = [];
            $errors = [];

            if ($supplierCode === null && $explicitCustomerCode === null) {
                $codeRuleCounts['both_codes_missing']++;
            }

            if ($explicitCustomerCode === null) {
                $codeRuleCounts['customer_code_blank']++;
            } elseif ($explicitCustomerCode === $supplierCode) {
                $codeRuleCounts['customer_code_equal']++;
            } else {
                $codeRuleCounts['customer_code_different']++;
            }

            if ($supplierCode === null) {
                $errors[] = 'supplier_code_missing';
            }

            if ($description === null) {
                $errors[] = 'description_missing';
            }

            if ($netPrice === null) {
                $errors[] = 'net_price_missing_or_invalid';
            } elseif ($netPrice <= 0) {
                $errors[] = 'net_price_not_positive';
            }

            if ($discount === null) {
                $warnings[] = 'discount_invalid';
            }

            if ($explicitCustomerCode !== null && $supplierCode !== null && $explicitCustomerCode !== $supplierCode) {
                $warnings[] = 'possible_supplier_code_change';
            }

            if ($salesUnit !== null && $packagingMultiplier !== null && $packagingMultiplier > 1) {
                $packagings[] = new CatalogPackaging(
                    fromUnit: 'CT',
                    toUnit: $salesUnit,
                    multiplier: $packagingMultiplier,
                    sourceField: 'Imballo',
                    sourceValue: $raw['packaging'],
                    mandatory: $packagingMandatory,
                );
            }

            if ($subpackagingMultiplier !== null && $subpackagingMultiplier > 1) {
                $warnings[] = 'subpackaging_multiplier_unmapped';
            } elseif ($subpackagingMandatory) {
                $warnings[] = 'subpackaging_constraint_without_multiplier';
            }

            if (
                $grossPrice !== null
                && $netPrice !== null
                && $discount !== null
                && abs(($grossPrice * (1 - ($discount / 100))) - $netPrice) > 0.01
            ) {
                $warnings[] = 'net_price_discount_mismatch';
            }

            $validFrom = $this->dateValue($raw['valid_from'] ?? null);
            $validTo = $this->dateValue($raw['valid_to'] ?? null);
            $validityKey = $validFrom.'|'.$validTo;
            $validityRanges[$validityKey] = [
                'valid_from' => $validFrom,
                'valid_to' => $validTo,
            ];

            $hashPayload = [
                'supplier' => 'IGROUP',
                'supplier_code' => $supplierCode,
                'customer_article_code' => $customerCode,
                'description' => $description,
                'sales_unit' => $salesUnit,
                'source_price' => $netPrice,
                'source_price_unit' => $priceUnit,
                'gross_price' => $grossPrice,
                'discount_percentage' => $discount,
                'vat_percentage' => null,
                'carton_price' => null,
                'orderable' => $netPrice !== null && $netPrice > 0,
                'non_orderable_reason' => $netPrice !== null && $netPrice > 0
                    ? null
                    : 'net_price_missing_or_not_positive',
                'packagings' => array_map(
                    static fn (CatalogPackaging $packaging): array => $packaging->toArray(),
                    $packagings
                ),
            ];

            $rows[] = new CatalogImportRow(
                supplier: 'IGROUP',
                sourceProfile: null,
                sourceFile: $document['file'],
                sourceSheet: (string) $sheet['name'],
                sourceRow: $line,
                supplierCode: $supplierCode,
                customerArticleCode: $customerCode,
                description: $description,
                category: null,
                salesUnit: $salesUnit,
                sourcePrice: $netPrice,
                sourcePriceUnit: $priceUnit,
                grossPrice: $grossPrice,
                discountPercentage: $discount,
                vatPercentage: null,
                cartonPrice: null,
                orderable: $netPrice !== null && $netPrice > 0,
                nonOrderableReason: $netPrice !== null && $netPrice > 0 ? null : 'net_price_missing_or_not_positive',
                packagings: $packagings,
                image: null,
                externalSourceId: $this->normalizer->code($raw['id'] ?? null),
                rawData: $this->redactSensitiveRawData($raw),
                sourceHash: $this->normalizer->stableHash($hashPayload),
                warnings: array_values(array_unique($warnings)),
                errors: array_values(array_unique($errors)),
            );
        }

        $recognizedColumns = array_keys($header['columns']);
        $unrecognizedColumns = array_values(array_filter(
            $header['raw_headers'],
            fn (string $value): bool => $this->canonicalHeader($value) === null
        ));

        return new CatalogParseResult(
            supplier: 'IGROUP',
            profile: null,
            sourceFile: $document['path'],
            fileHash: $document['file_hash'],
            rowsRead: count($rows),
            rows: $rows,
            ignoredRows: $ignoredRows,
            sheetStructures: [[
                'name' => $sheet['name'],
                'used_range' => $sheet['used_range'],
                'highest_row' => $sheet['highest_row'],
                'highest_data_row' => $sheet['highest_data_row'],
                'highest_column' => $sheet['highest_column'],
                'highest_data_column' => $sheet['highest_data_column'],
                'header_row' => $header['line'],
                'header_values' => $header['raw_headers'],
                'required_columns' => ['Cod. Articolo', 'Articolo', 'Prezzo Netto'],
                'optional_columns' => [
                    'Cod. Art. Cliente',
                    'UMprezzo',
                    'Prezzo',
                    'Sconto1-4',
                    'UM',
                    'Imballo',
                    'SubImballo',
                ],
                'ignored_columns' => [
                    'Cod. Cliente',
                    'Cliente',
                    'Listino',
                    'Imp_conai',
                    'peso_lordo',
                    'peso_netto',
                ],
                'merged_cells_count' => count($sheet['merged_cells']),
                'formula_count' => $sheet['formula_count'],
                'images_count' => count($sheet['images']),
                'tables' => $sheet['tables'],
                'validity_ranges' => array_values($validityRanges),
                'code_rule_counts' => $codeRuleCounts,
            ]],
            recognizedColumns: $recognizedColumns,
            unrecognizedColumns: $unrecognizedColumns,
            ignoredColumns: [
                'customer_id',
                'customer_name',
                'price_list_code',
                'price_list_name',
                'valid_from',
                'valid_to',
                'subpackaging',
                'conai',
                'gross_weight',
                'net_weight',
                'subpackaging_mandatory',
            ],
            unassociatedImages: [],
            warnings: [
                'Imballo maggiore di uno e rappresentato come relazione CT verso UM di vendita.',
                'SubImballo viene conservato nei dati grezzi ma non convertito senza evidenza semantica.',
                'Cod. Cliente, Cliente e Listino sono oscurati nel report JSON.',
            ],
            errors: [],
        );
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     * @return array{line:int, columns:array<string, int>, raw_headers:array<int, string>}|null
     */
    private function detectHeader(array $rows): ?array
    {
        foreach (array_slice($rows, 0, 20, true) as $line => $row) {
            $columns = [];
            $rawHeaders = [];

            foreach ($row as $column => $value) {
                $header = $this->normalizer->text($value);
                if ($header === null) {
                    continue;
                }

                $rawHeaders[] = $header;
                $canonical = $this->canonicalHeader($header);
                if ($canonical !== null && ! isset($columns[$canonical])) {
                    $columns[$canonical] = $column;
                }
            }

            if (isset($columns['supplier_code'], $columns['description'], $columns['net_price'])) {
                return [
                    'line' => (int) $line,
                    'columns' => $columns,
                    'raw_headers' => $rawHeaders,
                ];
            }
        }

        return null;
    }

    private function canonicalHeader(string $value): ?string
    {
        $normalized = Str::of($value)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish()
            ->toString();

        foreach (self::HEADER_ALIASES as $canonical => $aliases) {
            if (in_array($normalized, $aliases, true)) {
                return $canonical;
            }
        }

        return null;
    }

    /**
     * @param  array<int, mixed>  $row
     * @param  array<string, int>  $columns
     * @return array<string, mixed>
     */
    private function rowByCanonicalColumn(array $row, array $columns): array
    {
        $result = [];

        foreach ($columns as $canonical => $column) {
            $result[$canonical] = $row[$column] ?? null;
        }

        return $result;
    }

    /**
     * @param  array<int, mixed>  $row
     */
    private function rowIsEmpty(array $row): bool
    {
        foreach ($row as $value) {
            if ($this->normalizer->text($value) !== null) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function redactSensitiveRawData(array $raw): array
    {
        foreach (self::SENSITIVE_COLUMNS as $column) {
            if (array_key_exists($column, $raw)) {
                $raw[$column] = '[REDACTED]';
            }
        }

        return $raw;
    }

    private function dateValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            try {
                return ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
            } catch (Throwable) {
                return (string) $value;
            }
        }

        return $this->normalizer->text($value);
    }
}
