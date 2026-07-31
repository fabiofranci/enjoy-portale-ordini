<?php

declare(strict_types=1);

namespace App\Services\Imports\Catalog;

use App\Services\Imports\Catalog\Data\CatalogImportImage;
use App\Services\Imports\Catalog\Data\CatalogImportRow;
use App\Services\Imports\Catalog\Data\CatalogPackaging;
use App\Services\Imports\Catalog\Data\CatalogParseResult;
use App\Services\Imports\ScuoleListinoImportService;
use InvalidArgumentException;

final readonly class IcaCatalogParser
{
    private const SUPPORTED_PROFILE = 'scuole';

    public function __construct(
        private SpreadsheetDocumentReader $reader,
        private ScuoleListinoImportService $legacyParser,
        private CatalogImportNormalizer $normalizer,
    ) {}

    public function parse(string $filePath, ?string $profile): CatalogParseResult
    {
        $profile = strtolower(trim((string) $profile));

        if ($profile !== self::SUPPORTED_PROFILE) {
            throw new InvalidArgumentException(
                'Profilo ICA non supportato. Il solo profilo verificato nel Blocco 0 e: scuole.'
            );
        }

        $document = $this->reader->read($filePath);
        $legacy = $this->legacyParser->parseFile($document['path']);
        $sheet = $document['sheets'][0] ?? null;

        if (! is_array($sheet)) {
            throw new InvalidArgumentException('Il file ICA non contiene fogli leggibili.');
        }

        $imagesByRow = [];
        foreach ($sheet['images'] as $image) {
            if ($image instanceof CatalogImportImage) {
                $imagesByRow[$image->sourceRow][] = $image;
            }
        }

        $packagingByRow = [];
        foreach ($legacy['packaging']['valid'] ?? [] as $proposal) {
            $packagingByRow[(int) ($proposal['line'] ?? 0)][] = new CatalogPackaging(
                fromUnit: (string) $proposal['from_unit'],
                toUnit: (string) $proposal['to_unit'],
                multiplier: (float) $proposal['multiplier'],
                sourceField: (string) $proposal['source_field'],
                sourceValue: $proposal['source_value'] ?? null,
            );
        }

        $incompletePackagingByRow = [];
        foreach ($legacy['packaging']['incomplete'] ?? [] as $anomaly) {
            $incompletePackagingByRow[(int) ($anomaly['line'] ?? 0)][] = (string) ($anomaly['reason'] ?? 'packaging_incompleto');
        }

        $missingCategoryRows = [];
        foreach ($legacy['errors'] ?? [] as $error) {
            if (($error['reason'] ?? null) === 'categoria_mancante') {
                $missingCategoryRows[(int) ($error['line'] ?? 0)] = true;
            }
        }

        $rows = [];
        $associatedImages = [];
        $productRows = [];

        foreach ($legacy['unique_product_rows'] ?? [] as $sourceRow) {
            $line = (int) $sourceRow['line'];
            $productRows[$line] = true;
            $price = $sourceRow['price'];
            $rowImages = $imagesByRow[$line] ?? [];
            $image = $rowImages[0] ?? null;
            $warnings = array_values(array_unique($incompletePackagingByRow[$line] ?? []));
            $category = isset($missingCategoryRows[$line])
                ? null
                : $this->normalizer->text($sourceRow['category']['name'] ?? null);
            $categoryCode = $category === null
                ? null
                : $this->normalizer->code($sourceRow['category']['code'] ?? null);

            if ($category === null) {
                $warnings[] = 'category_missing';
            }

            if ($sourceRow['unit'] === null) {
                $warnings[] = 'sales_unit_missing';
            }

            if (($price['unita_prezzo_sorgente'] ?? null) === null) {
                $warnings[] = 'source_price_unit_missing';
            }

            if (count($rowImages) > 1) {
                $warnings[] = 'multiple_images_on_product_row';
            }

            if ($image instanceof CatalogImportImage) {
                $associatedImages[spl_object_id($image)] = true;
            }

            $packagings = $packagingByRow[$line] ?? [];
            $hashPayload = [
                'supplier' => 'ICA',
                'source_profile' => self::SUPPORTED_PROFILE,
                'supplier_code' => $this->normalizer->code($sourceRow['sku']),
                'customer_article_code' => $this->normalizer->code($sourceRow['sku']),
                'description' => $this->normalizer->text($sourceRow['description']),
                'category' => $category,
                'category_code' => $categoryCode,
                'sales_unit' => $this->normalizer->unit($sourceRow['unit']),
                'source_price' => $this->normalizer->decimal($price['prezzo_sorgente'] ?? $price['prezzo'] ?? null),
                'source_price_unit' => $this->normalizer->unit($price['unita_prezzo_sorgente'] ?? null),
                'gross_price' => isset($legacy['header']['columns']['prezzo_lordo'])
                    ? $this->normalizer->decimal($price['prezzo_lordo'] ?? null)
                    : null,
                'discount_percentage' => isset($legacy['header']['columns']['sconto'])
                    ? $this->normalizer->decimal($price['sconto_percentuale'] ?? null)
                    : null,
                'vat_percentage' => isset($legacy['header']['columns']['iva'])
                    ? $this->normalizer->decimal($price['iva_percentuale'] ?? null)
                    : null,
                'carton_price' => $this->normalizer->decimal($price['prezzo_cartone'] ?? null),
                'orderable' => (bool) $price['ordinabile'],
                'non_orderable_reason' => $price['motivo_non_ordinabile'] ?? null,
                'packagings' => array_map(
                    static fn (CatalogPackaging $packaging): array => $packaging->toArray(),
                    $packagings
                ),
                'image_hash' => $image?->contentHash,
            ];

            $rows[] = new CatalogImportRow(
                supplier: 'ICA',
                sourceProfile: self::SUPPORTED_PROFILE,
                sourceFile: $document['file'],
                sourceSheet: (string) $sheet['name'],
                sourceRow: $line,
                supplierCode: $this->normalizer->code($sourceRow['sku']),
                customerArticleCode: $this->normalizer->code($sourceRow['sku']),
                description: $this->normalizer->text($sourceRow['description']),
                category: $category,
                categoryCode: $categoryCode,
                parentCategory: null,
                parentCategoryCode: null,
                salesUnit: $this->normalizer->unit($sourceRow['unit']),
                sourcePrice: $this->normalizer->decimal($price['prezzo_sorgente'] ?? $price['prezzo'] ?? null),
                sourcePriceUnit: $this->normalizer->unit($price['unita_prezzo_sorgente'] ?? null),
                grossPrice: isset($legacy['header']['columns']['prezzo_lordo'])
                    ? $this->normalizer->decimal($price['prezzo_lordo'] ?? null)
                    : null,
                discountPercentage: isset($legacy['header']['columns']['sconto'])
                    ? $this->normalizer->decimal($price['sconto_percentuale'] ?? null)
                    : null,
                vatPercentage: isset($legacy['header']['columns']['iva'])
                    ? $this->normalizer->decimal($price['iva_percentuale'] ?? null)
                    : null,
                cartonPrice: $this->normalizer->decimal($price['prezzo_cartone'] ?? null),
                orderable: (bool) $price['ordinabile'],
                nonOrderableReason: $this->normalizer->text($price['motivo_non_ordinabile'] ?? null),
                packagings: $packagings,
                image: $image instanceof CatalogImportImage ? $image : null,
                externalSourceId: null,
                rawData: is_array($sourceRow['raw'] ?? null) ? $sourceRow['raw'] : [],
                sourceHash: $this->normalizer->stableHash($hashPayload),
                warnings: array_values(array_unique($warnings)),
                errors: [],
            );
        }

        $unassociatedImages = [];
        foreach ($sheet['images'] as $image) {
            if (! $image instanceof CatalogImportImage || isset($associatedImages[spl_object_id($image)])) {
                continue;
            }

            $unassociatedImages[] = $image->toArray() + [
                'reason' => isset($productRows[$image->sourceRow])
                    ? 'additional_image_on_product_row'
                    : 'no_exact_product_row',
            ];
        }

        $recognizedColumns = array_keys($legacy['header']['columns'] ?? []);
        $headerValues = array_values(array_filter(
            $legacy['header']['raw'] ?? [],
            static fn (mixed $value): bool => trim((string) $value) !== ''
        ));

        return new CatalogParseResult(
            supplier: 'ICA',
            profile: self::SUPPORTED_PROFILE,
            sourceFile: $document['path'],
            fileHash: $document['file_hash'],
            rowsRead: (int) ($legacy['rows_total'] ?? 0),
            rows: $rows,
            ignoredRows: array_values($legacy['ignored_rows'] ?? []),
            sheetStructures: [[
                'name' => $sheet['name'],
                'used_range' => $sheet['used_range'],
                'highest_row' => $sheet['highest_row'],
                'highest_data_row' => $sheet['highest_data_row'],
                'highest_column' => $sheet['highest_column'],
                'highest_data_column' => $sheet['highest_data_column'],
                'header_row' => $legacy['header']['line'] ?? null,
                'header_values' => $headerValues,
                'required_columns' => ['Codice prodotto', 'Descrizione'],
                'optional_columns' => [
                    'Confezionamento',
                    'Prezzo per unita di vendita',
                    'Prezzo al cartone',
                ],
                'ignored_columns' => ['Immagine (contenuto analizzato separatamente)'],
                'merged_cells_count' => count($sheet['merged_cells']),
                'formula_count' => $sheet['formula_count'],
                'images_count' => count($sheet['images']),
                'tables' => $sheet['tables'],
                'section_rows_count' => count($legacy['section_rows'] ?? []),
                'section_rows' => $legacy['section_rows'] ?? [],
                'source_duplicate_diagnostics' => $legacy['duplicates'] ?? [],
            ]],
            recognizedColumns: $recognizedColumns,
            unrecognizedColumns: [],
            ignoredColumns: ['Immagine (colonna; i contenuti incorporati sono analizzati separatamente)'],
            unassociatedImages: $unassociatedImages,
            warnings: [
                'Le immagini sono associate solo quando ancorate alla stessa riga del prodotto.',
                'Il file non dichiara in modo affidabile l unita di prezzo per tutte le righe.',
            ],
            errors: [],
        );
    }
}
