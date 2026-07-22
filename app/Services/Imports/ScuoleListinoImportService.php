<?php

namespace App\Services\Imports;

use App\Models\Categoria;
use App\Models\Listino;
use App\Models\ListinoProdotto;
use App\Models\Product;
use App\Models\ProductPackaging;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use RuntimeException;

class ScuoleListinoImportService
{
    private const HEADER_ALIASES = [
        'categoria' => ['cat', 'categoria', 'sezione'],
        'sku' => ['codice', 'codice prodotto', 'sku'],
        'descrizione' => ['descrizione', 'nome prodotto', 'prodotto'],
        'prezzo_lordo' => ['prezzo lordo', 'lordo'],
        'prezzo' => ['prezzo netto', 'prezzo', 'prezzo unitario', 'prezzo per unita vendita', 'prezzo per unità vendita', 'prezzo per unita di vendita', 'prezzo per unità di vendita'],
        'prezzo_cartone' => ['prezzo al cartone'],
        'iva' => ['iva', 'iva %', 'aliquota iva'],
        'sconto' => ['sconto', 'sconto %'],
        'unita_misura' => ['um', 'udm', 'unita di misura', 'unita misura', 'unità di misura'],
        'confezionamento' => ['confezionamento'],
        'imballo' => ['imballo'],
        'subimballo' => ['subimballo', 'sub imballo'],
        'tassativo' => ['tassativo'],
    ];

    /**
     * @param  array{listino?:string, valid_from?:string|null, valid_to?:string|null}  $options
     * @return array<string, mixed>
     */
    public function dryRun(string $filePath, array $options = []): array
    {
        $parsed = $this->parseFile($filePath);
        $comparison = $this->compareWithDatabase($parsed, [
            'listino' => (string) ($options['listino'] ?? 'Scuole'),
            'valid_from' => $options['valid_from'] ?? null,
            'valid_to' => $options['valid_to'] ?? null,
        ]);

        foreach ($comparison as $key => $value) {
            $parsed[$key] = $value;
        }

        return $this->withSummary($parsed);
    }

    /**
     * @param  array{listino?:string, valid_from?:string|null, valid_to?:string|null}  $options
     * @return array<string, mixed>
     */
    public function import(string $filePath, array $options = []): array
    {
        $parsed = $this->parseFile($filePath);

        if (($parsed['errors'] ?? []) !== []) {
            throw new RuntimeException('Import interrotto: il file contiene errori parser non gestibili.');
        }

        $options = $this->resolveImportOptions($filePath, $options);

        $written = DB::transaction(function () use ($parsed, $options): array {
            return $this->writeImport($parsed, $options);
        });

        foreach ($written as $key => $value) {
            $parsed[$key] = $value;
        }

        return $this->withSummary($parsed);
    }

    /**
     * @return array<string, mixed>
     */
    public function parseFile(string $filePath): array
    {
        if (!is_file($filePath)) {
            throw new InvalidArgumentException("File non trovato: {$filePath}");
        }

        $rows = $this->loadSheetRows($filePath);
        $header = $this->detectHeader($rows);

        if ($header === null) {
            throw new InvalidArgumentException('Intestazione non riconosciuta: servono almeno codice/SKU e descrizione/nome prodotto.');
        }

        return $this->parseRows($filePath, $rows, $header);
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function loadSheetRows(string $filePath): array
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        if ($extension === 'csv') {
            $reader = new Csv();
            $reader->setReadDataOnly(true);
            $reader->setDelimiter($this->detectCsvDelimiter($filePath));
            $reader->setEnclosure('"');
            $reader->setSheetIndex(0);
        } elseif (in_array($extension, ['xls', 'xlsx'], true)) {
            $reader = IOFactory::createReaderForFile($filePath);
            $reader->setReadDataOnly(false);
        } else {
            throw new InvalidArgumentException('Formato file non supportato. Usare CSV, XLS o XLSX.');
        }

        $spreadsheet = $reader->load($filePath);
        $worksheet = $spreadsheet->getActiveSheet();
        $rows = $worksheet->toArray(null, true, true, false);

        if (in_array($extension, ['xls', 'xlsx'], true)) {
            $rows = $this->fillMergedDataColumns($worksheet, $rows);
        }

        return $rows;
    }

    /**
     * PhpSpreadsheet keeps merged-cell values only in the top-left cell. The
     * school price list merges price/packaging columns across product rows, so
     * copy those values without copying SKU/description cells into detail rows.
     *
     * @param  array<int, array<int, mixed>>  $rows
     * @return array<int, array<int, mixed>>
     */
    private function fillMergedDataColumns(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $worksheet, array $rows): array
    {
        foreach ($worksheet->getMergeCells() as $range) {
            [$start, $end] = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::rangeBoundaries($range);
            [$startColumn, $startRow] = $start;
            [$endColumn, $endRow] = $end;
            $source = $rows[$startRow - 1][$startColumn - 1] ?? null;

            if ($source === null || $this->cellString($source) === '') {
                continue;
            }

            for ($row = $startRow; $row <= $endRow; $row++) {
                for ($column = max(4, $startColumn); $column <= $endColumn; $column++) {
                    if (($rows[$row - 1][$column - 1] ?? null) === null || $this->cellString($rows[$row - 1][$column - 1] ?? null) === '') {
                        $rows[$row - 1][$column - 1] = $source;
                    }
                }
            }
        }

        return $rows;
    }

    private function detectCsvDelimiter(string $filePath): string
    {
        $handle = fopen($filePath, 'r');

        if ($handle === false) {
            return ',';
        }

        $firstLine = (string) fgets($handle);
        fclose($handle);

        return substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     * @return array{row_index:int, line:int, raw:array<int, mixed>, columns:array<string, int>}|null
     */
    private function detectHeader(array $rows): ?array
    {
        foreach (array_slice($rows, 0, 20, true) as $rowIndex => $row) {
            $columns = [];

            foreach ($row as $columnIndex => $value) {
                $canonical = $this->canonicalHeader((string) $value);

                if ($canonical !== null && !isset($columns[$canonical])) {
                    $columns[$canonical] = (int) $columnIndex;
                }
            }

            if (isset($columns['sku'], $columns['descrizione']) && count($columns) >= 3) {
                return [
                    'row_index' => (int) $rowIndex,
                    'line' => (int) $rowIndex + 1,
                    'raw' => $row,
                    'columns' => $columns,
                ];
            }
        }

        return null;
    }

    private function canonicalHeader(string $value): ?string
    {
        $normalized = $this->normalizeTextKey($value);

        foreach (self::HEADER_ALIASES as $canonical => $aliases) {
            foreach ($aliases as $alias) {
                if ($normalized === $this->normalizeTextKey($alias)) {
                    return $canonical;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     * @param  array{row_index:int, line:int, raw:array<int, mixed>, columns:array<string, int>}  $header
     * @return array{line:int, category:array{key:string, name:string, code:?string, source:string}, source:string}|null
     */
    private function initialCategoryBeforeHeader(array $rows, array $header): ?array
    {
        for ($rowIndex = $header['row_index'] - 1; $rowIndex >= 0; $rowIndex--) {
            $row = $rows[$rowIndex] ?? [];

            if ($this->rowIsEmpty($row) || $this->rowIsRepeatedHeader($row, $header['columns'])) {
                continue;
            }

            $category = $this->sectionCategoryFromRow(
                $row,
                $header['columns'],
                $this->cellString($this->value($row, $header['columns'], 'sku')),
                $this->normalizeSpaces($this->cellString($this->value($row, $header['columns'], 'descrizione')))
            );

            if ($category !== null) {
                return [
                    'line' => (int) $rowIndex + 1,
                    'category' => $category,
                    'source' => $category['source'],
                ];
            }
        }

        return null;
    }

    /**
     * @param  array<int, mixed>  $row
     * @param  array<string, int>  $columns
     * @return array{key:string, name:string, code:?string, source:string}|null
     */
    private function sectionCategoryFromRow(array $row, array $columns, string $sku, string $description): ?array
    {
        if ($sku !== '') {
            return null;
        }

        $categoryValue = $this->cellString($this->value($row, $columns, 'categoria'));

        if ($categoryValue !== '' && $description === '') {
            return $this->normalizeCategory($categoryValue);
        }

        if (isset($columns['categoria']) && $categoryValue === '' && $description !== '') {
            return $this->normalizeCategory($description);
        }

        if (!isset($columns['categoria'])) {
            $skuColumn = $columns['sku'] ?? 1;

            for ($column = 0; $column < $skuColumn; $column++) {
                $candidate = $this->cellString($row[$column] ?? null);

                if ($candidate !== '' && $description === '') {
                    return $this->normalizeCategory($candidate);
                }
            }
        }

        return null;
    }

    /**
     * @param  array<int, mixed>  $row
     * @param  array<string, int>  $columns
     */
    private function rowIsRepeatedHeader(array $row, array $columns): bool
    {
        $sku = $this->cellString($this->value($row, $columns, 'sku'));
        $description = $this->cellString($this->value($row, $columns, 'descrizione'));

        return $this->canonicalHeader($sku) === 'sku'
            && $this->canonicalHeader($description) === 'descrizione';
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     * @param  array<int, mixed>  $row
     * @param  array<string, int>  $columns
     * @return array<int, mixed>
     */
    private function hydrateProductRowFromAdjacentRows(array $rows, int $rowIndex, array $row, array $columns): array
    {
        if ($this->cellString($this->value($row, $columns, 'sku')) === '') {
            return $row;
        }

        $nextRow = $rows[$rowIndex + 1] ?? null;

        if (!is_array($nextRow) || $this->cellString($this->value($nextRow, $columns, 'sku')) !== '') {
            return $row;
        }

        if ($this->rowIsRepeatedHeader($nextRow, $columns) || $this->sectionCategoryFromRow(
            $nextRow,
            $columns,
            '',
            $this->normalizeSpaces($this->cellString($this->value($nextRow, $columns, 'descrizione')))
        ) !== null) {
            return $row;
        }

        foreach (['descrizione', 'prezzo', 'prezzo_lordo', 'prezzo_cartone', 'confezionamento', 'imballo', 'subimballo', 'tassativo', 'unita_misura'] as $canonical) {
            if (!isset($columns[$canonical])) {
                continue;
            }

            $column = $columns[$canonical];

            if ($this->cellString($row[$column] ?? null) === '' && $this->cellString($nextRow[$column] ?? null) !== '') {
                $row[$column] = $nextRow[$column];
            }
        }

        return $row;
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     * @param  array{row_index:int, line:int, raw:array<int, mixed>, columns:array<string, int>}  $header
     * @return array<string, mixed>
     */
    private function parseRows(string $filePath, array $rows, array $header): array
    {
        $parsedRows = [];
        $uniqueRows = [];
        $sectionRows = [];
        $ignoredRows = [];
        $errors = [];
        $invalidPrices = [];
        $categoriesByKey = [];
        $duplicates = [
            'identical' => [],
            'conflicting' => [],
        ];
        $packaging = [
            'valid' => [],
            'incomplete' => [],
            'tassativi' => [],
        ];
        $seenBySku = [];
        $conflictingSkus = [];
        $currentCategory = null;
        $initialCategory = $this->initialCategoryBeforeHeader($rows, $header);

        if ($initialCategory !== null) {
            $currentCategory = $initialCategory['category'];
            $sectionRows[] = $initialCategory;
            $categoriesByKey[$currentCategory['key']] = $currentCategory;
        }

        foreach ($rows as $rowIndex => $row) {
            if ($rowIndex <= $header['row_index']) {
                continue;
            }

            $line = (int) $rowIndex + 1;

            if ($this->rowIsEmpty($row)) {
                $ignoredRows[] = $this->anomaly($line, '', '', '', '', 'riga_vuota');
                continue;
            }

            if ($this->rowIsRepeatedHeader($row, $header['columns'])) {
                $ignoredRows[] = $this->anomaly($line, '', '', '', '', 'intestazione_ripetuta');
                continue;
            }

            $originalSku = $this->cellString($this->value($row, $header['columns'], 'sku'));
            $originalDescription = $this->normalizeSpaces($this->cellString($this->value($row, $header['columns'], 'descrizione')));
            $sectionCategory = $this->sectionCategoryFromRow($row, $header['columns'], $originalSku, $originalDescription);

            if ($sectionCategory !== null) {
                $currentCategory = $sectionCategory;
                $sectionRows[] = [
                    'line' => $line,
                    'category' => $sectionCategory,
                    'source' => $sectionCategory['source'],
                ];
                $categoriesByKey[$sectionCategory['key']] = $sectionCategory;

                continue;
            }

            if ($originalSku === '' && $originalDescription !== '' && !isset($header['columns']['categoria'])) {
                $ignoredRows[] = $this->anomaly($line, '', $originalDescription, $currentCategory['name'] ?? '', '', 'riga_descrittiva');
                continue;
            }

            $row = $this->hydrateProductRowFromAdjacentRows($rows, $rowIndex, $row, $header['columns']);
            $skuOriginal = $this->cellString($this->value($row, $header['columns'], 'sku'));
            $description = $this->normalizeSpaces($this->cellString($this->value($row, $header['columns'], 'descrizione')));
            $categoryValue = $this->cellString($this->value($row, $header['columns'], 'categoria'));
            $sku = $this->normalizeSku($skuOriginal);

            if ($sku === '' || $description === '') {
                $ignoredRows[] = $this->anomaly($line, $skuOriginal, $description, $categoryValue, '', 'codice_o_descrizione_mancante');
                continue;
            }

            $category = $categoryValue !== ''
                ? $this->normalizeCategory($categoryValue)
                : $currentCategory;

            if ($category === null) {
                $errors[] = $this->anomaly($line, $sku, $description, '', $categoryValue, 'categoria_mancante');
                $category = $this->normalizeCategory('Senza categoria');
            }

            $currentCategory = $category;
            $categoriesByKey[$category['key']] = $category;

            $price = $this->parsePrice($row, $header['columns']);
            $packagingForRow = $this->parsePackaging($row, $header['columns'], $line, $sku, $description, $category);

            foreach ($packagingForRow['valid'] as $proposal) {
                $packaging['valid'][] = $proposal;
            }
            foreach ($packagingForRow['incomplete'] as $incomplete) {
                $packaging['incomplete'][] = $incomplete;
            }
            foreach ($packagingForRow['tassativi'] as $tassativo) {
                $packaging['tassativi'][] = $tassativo;
            }

            $parsedRow = [
                'line' => $line,
                'sku_original' => $skuOriginal,
                'sku' => $sku,
                'description' => $description,
                'category' => $category,
                'unit' => $this->normalizeUnit($this->cellString($this->value($row, $header['columns'], 'unita_misura'))),
                'price' => $price,
                'packaging_text' => $packagingForRow['text'],
                'tassativo' => $packagingForRow['tassativo'],
                'raw' => $this->rawRowByCanonical($row, $header['columns']),
            ];

            if (!$price['ordinabile']) {
                $invalidPrices[] = $this->anomaly(
                    $line,
                    $sku,
                    $description,
                    $category['name'],
                    $price['source_value'],
                    $price['motivo_non_ordinabile']
                );

                if ($price['motivo_non_ordinabile'] === 'prezzo_non_numerico') {
                    $errors[] = $this->anomaly(
                        $line,
                        $sku,
                        $description,
                        $category['name'],
                        $price['source_value'],
                        'prezzo_non_numerico'
                    );
                }
            }

            $signature = $this->productSignature($parsedRow);

            if (!isset($seenBySku[$sku])) {
                $seenBySku[$sku] = [
                    'signature' => $signature,
                    'row' => $parsedRow,
                    'lines' => [$line],
                ];
                $parsedRows[] = $parsedRow;
                continue;
            }

            if ($seenBySku[$sku]['signature'] === $signature) {
                $duplicates['identical'][] = [
                    'sku' => $sku,
                    'first_line' => $seenBySku[$sku]['lines'][0],
                    'duplicate_line' => $line,
                    'description' => $description,
                    'category' => $category['name'],
                ];
                $seenBySku[$sku]['lines'][] = $line;
                continue;
            }

            $conflictingSkus[$sku] = true;
            $conflict = [
                'sku' => $sku,
                'lines' => array_values(array_unique([...$seenBySku[$sku]['lines'], $line])),
                'first' => $this->conflictRowPayload($seenBySku[$sku]['row']),
                'current' => $this->conflictRowPayload($parsedRow),
                'reasons' => $this->duplicateConflictReasons($seenBySku[$sku]['row'], $parsedRow),
            ];

            $duplicates['conflicting'][] = $conflict;
            $errors[] = $this->anomaly(
                $line,
                $sku,
                $description,
                $category['name'],
                $skuOriginal,
                'sku_duplicato_conflittuale'
            );
        }

        foreach ($seenBySku as $sku => $seen) {
            if (!isset($conflictingSkus[$sku])) {
                $uniqueRows[] = $seen['row'];
            }
        }

        return [
            'file' => [
                'path' => $filePath,
                'type' => strtolower(pathinfo($filePath, PATHINFO_EXTENSION)),
            ],
            'header' => [
                'line' => $header['line'],
                'raw' => array_values($header['raw']),
                'columns' => $header['columns'],
            ],
            'rows_total' => max(0, count($rows) - $header['row_index'] - 1),
            'product_rows' => $parsedRows,
            'unique_product_rows' => $uniqueRows,
            'section_rows' => $sectionRows,
            'ignored_rows' => $ignoredRows,
            'categories' => [
                'parsed' => array_values($categoriesByKey),
            ],
            'prices' => [
                'invalid' => $invalidPrices,
            ],
            'packaging' => $packaging,
            'duplicates' => $duplicates,
            'errors' => $errors,
        ];
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @param  array{listino:string, valid_from:string, valid_to:?string}  $options
     * @return array<string, mixed>
     */
    private function writeImport(array $parsed, array $options): array
    {
        $listinoResult = $this->writeListino($options);
        $categoryResult = $this->writeCategories($parsed['categories']['parsed']);
        $productResult = $this->writeProductsAndPrices(
            $parsed['unique_product_rows'],
            $categoryResult['models_by_key'],
            $listinoResult['model']
        );
        $packagingResult = $this->writePackaging(
            $parsed['packaging'],
            $parsed['unique_product_rows'],
            $productResult['models_by_sku']
        );

        return [
            'options' => $options + [
                'listino_id' => $listinoResult['model']->id,
                'listino_exists' => !$listinoResult['created'],
                'execute' => true,
            ],
            'listino' => $listinoResult['report'],
            'categories' => $parsed['categories'] + [
                'new' => $categoryResult['created'],
                'existing' => array_merge($categoryResult['updated'], $categoryResult['unchanged']),
                'to_update' => $categoryResult['updated'],
                'unchanged' => $categoryResult['unchanged'],
            ],
            'products' => [
                'new' => $productResult['created'],
                'to_update' => $productResult['updated'],
                'unchanged' => $productResult['unchanged'],
            ],
            'prices' => [
                'new' => $productResult['prices_created'],
                'to_update' => $productResult['prices_updated'],
                'unchanged' => $productResult['prices_unchanged'],
                'invalid' => $parsed['prices']['invalid'],
            ],
            'packaging' => $parsed['packaging'] + [
                'created' => $packagingResult['created'],
                'updated' => $packagingResult['updated'],
                'unchanged' => $packagingResult['unchanged'],
                'conflicting' => $packagingResult['conflicting'],
                'ignored_incomplete' => $parsed['packaging']['incomplete'] ?? [],
            ],
            'write_summary' => [
                'listino_created' => $listinoResult['created'] ? 1 : 0,
                'listino_updated' => $listinoResult['updated'] ? 1 : 0,
                'listino_unchanged' => (!$listinoResult['created'] && !$listinoResult['updated']) ? 1 : 0,
                'categorie_create' => count($categoryResult['created']),
                'categorie_update' => count($categoryResult['updated']),
                'categorie_invariate' => count($categoryResult['unchanged']),
                'prodotti_create' => count($productResult['created']),
                'prodotti_update' => count($productResult['updated']),
                'prodotti_invariati' => count($productResult['unchanged']),
                'prezzi_create' => count($productResult['prices_created']),
                'prezzi_update' => count($productResult['prices_updated']),
                'prezzi_invariati' => count($productResult['prices_unchanged']),
                'packaging_create' => count($packagingResult['created']),
                'packaging_update' => count($packagingResult['updated']),
                'packaging_invariati' => count($packagingResult['unchanged']),
                'packaging_incompleti_ignorati' => count($parsed['packaging']['incomplete'] ?? []),
                'packaging_conflittuali' => count($packagingResult['conflicting']),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{listino:string, valid_from:string, valid_to:?string}
     */
    private function resolveImportOptions(string $filePath, array $options): array
    {
        $listino = trim((string) ($options['listino'] ?? 'Scuole'));
        $listino = $listino !== '' ? $listino : 'Scuole';
        $sourceYear = $this->yearFromFileName($filePath);
        $validFrom = trim((string) ($options['valid_from'] ?? ''));
        $validTo = trim((string) ($options['valid_to'] ?? ''));

        if ($validFrom === '') {
            if ($sourceYear === null) {
                throw new InvalidArgumentException('Per l\'import reale serve --valid-from, oppure un anno desumibile dal nome file.');
            }

            $validFrom = $sourceYear . '-01-01';
        }

        if ($validTo === '' && $sourceYear !== null) {
            $validTo = $sourceYear . '-12-31';
        }

        return [
            'listino' => $listino,
            'valid_from' => Carbon::parse($validFrom)->toDateString(),
            'valid_to' => $validTo !== '' ? Carbon::parse($validTo)->toDateString() : null,
        ];
    }

    private function yearFromFileName(string $filePath): ?string
    {
        $name = pathinfo($filePath, PATHINFO_FILENAME);

        return preg_match('/(^|[^0-9])(20[0-9]{2})(?![0-9])/', $name, $matches) === 1 ? $matches[2] : null;
    }

    /**
     * @param  array{listino:string, valid_from:string, valid_to:?string}  $options
     * @return array{model:Listino, created:bool, updated:bool, report:array<string, mixed>}
     */
    private function writeListino(array $options): array
    {
        $matches = Listino::query()
            ->where('nome_listino', $options['listino'])
            ->get();

        if ($matches->count() > 1) {
            throw new RuntimeException(sprintf(
                'Import interrotto: esistono %d listini con nome %s.',
                $matches->count(),
                $options['listino']
            ));
        }

        $attributes = [
            'nome_listino' => $options['listino'],
            'tipo' => 'vendita',
            'sconto_percentuale' => 0,
            'valido_dal' => $options['valid_from'],
            'valido_al' => $options['valid_to'],
            'attivo' => true,
        ];

        /** @var Listino|null $listino */
        $listino = $matches->first();

        if ($listino === null) {
            $listino = Listino::create($attributes);

            return [
                'model' => $listino,
                'created' => true,
                'updated' => false,
                'report' => ['id' => $listino->id, 'status' => 'created'] + $attributes,
            ];
        }

        $listino->fill($attributes);
        $changes = array_keys($listino->getDirty());

        if ($changes !== []) {
            $listino->save();
        }

        return [
            'model' => $listino,
            'created' => false,
            'updated' => $changes !== [],
            'report' => ['id' => $listino->id, 'status' => $changes !== [] ? 'updated' : 'unchanged', 'changes' => $changes] + $attributes,
        ];
    }

    /**
     * @param  array<int, array{key:string, name:string, code:?string, source:string}>  $categories
     * @return array{models_by_key:array<string, Categoria>, created:array<int, array<string, mixed>>, updated:array<int, array<string, mixed>>, unchanged:array<int, array<string, mixed>>}
     */
    private function writeCategories(array $categories): array
    {
        $modelsByKey = Categoria::query()
            ->get()
            ->mapWithKeys(fn (Categoria $categoria): array => [$this->normalizeCategory($categoria->nome)['key'] => $categoria])
            ->all();
        $created = [];
        $updated = [];
        $unchanged = [];

        foreach ($categories as $category) {
            $model = $modelsByKey[$category['key']] ?? null;
            $attributes = [
                'nome' => $category['name'],
            ];

            if (($category['code'] ?? null) !== null) {
                $attributes['codice'] = $category['code'];
            }

            if (!$model instanceof Categoria) {
                $model = Categoria::create($attributes + [
                    'percentuale_ricarico' => 0,
                    'categoria_padre_id' => null,
                ]);
                $modelsByKey[$category['key']] = $model;
                $created[] = $category + ['id' => $model->id];

                continue;
            }

            $model->fill($attributes);
            $changes = array_keys($model->getDirty());

            if ($changes !== []) {
                $model->save();
                $updated[] = $category + ['id' => $model->id, 'changes' => $changes];
            } else {
                $unchanged[] = $category + ['id' => $model->id];
            }
        }

        return [
            'models_by_key' => $modelsByKey,
            'created' => $created,
            'updated' => $updated,
            'unchanged' => $unchanged,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, Categoria>  $categoriesByKey
     * @return array{
     *     models_by_sku:array<string, Product>,
     *     created:array<int, array<string, mixed>>,
     *     updated:array<int, array<string, mixed>>,
     *     unchanged:array<int, array<string, mixed>>,
     *     prices_created:array<int, array<string, mixed>>,
     *     prices_updated:array<int, array<string, mixed>>,
     *     prices_unchanged:array<int, array<string, mixed>>
     * }
     */
    private function writeProductsAndPrices(array $rows, array $categoriesByKey, Listino $listino): array
    {
        $skus = collect($rows)->pluck('sku')->all();
        $productsBySku = Product::withTrashed()
            ->whereIn('codice', $skus)
            ->get()
            ->mapWithKeys(fn (Product $product): array => [$this->normalizeSku((string) $product->codice) => $product])
            ->all();
        $created = [];
        $updated = [];
        $unchanged = [];
        $pricesCreated = [];
        $pricesUpdated = [];
        $pricesUnchanged = [];

        foreach ($rows as $row) {
            $category = $categoriesByKey[$row['category']['key']] ?? null;

            if (!$category instanceof Categoria) {
                throw new RuntimeException('Categoria non disponibile per SKU ' . $row['sku']);
            }

            $product = $productsBySku[$row['sku']] ?? null;
            $attributes = [
                'nome' => $row['description'],
                'categoria_id' => $category->id,
                'unita_misura' => $row['unit'],
                'disponibile' => true,
            ];

            if (!$product instanceof Product) {
                $product = Product::create($attributes + [
                    'codice' => $row['sku'],
                ]);
                $productsBySku[$row['sku']] = $product;
                $created[] = $this->productReportPayload($row, [], $product->id);
            } else {
                $product->fill($attributes);
                $changes = array_keys($product->getDirty());

                if (method_exists($product, 'trashed') && $product->trashed()) {
                    $product->restore();
                    $changes[] = 'restored';
                }

                if ($changes !== []) {
                    $product->save();
                    $updated[] = $this->productReportPayload($row, array_values(array_unique($changes)), $product->id);
                } else {
                    $unchanged[] = $this->productReportPayload($row, [], $product->id);
                }
            }

            $pricePayload = $this->pivotPayload($row);
            $pivot = ListinoProdotto::query()
                ->where('listino_id', $listino->id)
                ->where('product_id', $product->id)
                ->first();
            $reportPayload = $this->priceReportPayload($row, (string) $listino->nome_listino, $listino->id, $product->id);

            if (!$pivot instanceof ListinoProdotto) {
                ListinoProdotto::create($pricePayload + [
                    'listino_id' => $listino->id,
                    'product_id' => $product->id,
                ]);
                $pricesCreated[] = $reportPayload;

                continue;
            }

            $pivot->fill($pricePayload);
            $changes = array_keys($pivot->getDirty());

            if ($changes !== []) {
                $pivot->save();
                $pricesUpdated[] = $reportPayload + ['changes' => $changes];
            } else {
                $pricesUnchanged[] = $reportPayload;
            }
        }

        return [
            'models_by_sku' => $productsBySku,
            'created' => $created,
            'updated' => $updated,
            'unchanged' => $unchanged,
            'prices_created' => $pricesCreated,
            'prices_updated' => $pricesUpdated,
            'prices_unchanged' => $pricesUnchanged,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function pivotPayload(array $row): array
    {
        return [
            'prezzo_lordo' => $row['price']['prezzo_lordo'],
            'sconto_percentuale' => $row['price']['sconto_percentuale'],
            'prezzo' => $row['price']['prezzo'],
            'iva_percentuale' => $row['price']['iva_percentuale'],
            'ordinabile' => (bool) $row['price']['ordinabile'],
            'motivo_non_ordinabile' => $row['price']['motivo_non_ordinabile'],
            'prezzo_sorgente' => $row['price']['prezzo_sorgente'],
            'unita_prezzo_sorgente' => $row['price']['unita_prezzo_sorgente'],
        ];
    }

    /**
     * @param  array<string, mixed>  $packaging
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, Product>  $productsBySku
     * @return array{created:array<int, array<string, mixed>>, updated:array<int, array<string, mixed>>, unchanged:array<int, array<string, mixed>>, conflicting:array<int, array<string, mixed>>}
     */
    private function writePackaging(array $packaging, array $rows, array $productsBySku): array
    {
        $rowsBySku = collect($rows)->keyBy('sku')->all();
        $created = [];
        $updated = [];
        $unchanged = [];
        $conflicting = [];
        $seenPairs = [];

        foreach ($packaging['valid'] ?? [] as $proposal) {
            $product = $productsBySku[$proposal['sku']] ?? null;
            $row = $rowsBySku[$proposal['sku']] ?? null;
            $multiplier = round((float) ($proposal['multiplier'] ?? 0), 5);
            $fromUnit = $this->normalizeUnit((string) ($proposal['from_unit'] ?? ''));
            $toUnit = $this->normalizeUnit((string) ($proposal['to_unit'] ?? ''));

            if (!$product instanceof Product || !is_array($row) || $fromUnit === null || $toUnit === null || $fromUnit === $toUnit || $multiplier <= 1.0) {
                $conflicting[] = $proposal + ['reason' => 'packaging_non_valido'];
                continue;
            }

            $pairKey = implode(':', [$product->id, $fromUnit, $toUnit]);

            if (isset($seenPairs[$pairKey]) && round((float) $seenPairs[$pairKey], 5) !== $multiplier) {
                $conflicting[] = $proposal + ['reason' => 'packaging_conflitto_stessa_coppia'];
                continue;
            }

            $seenPairs[$pairKey] = $multiplier;
            $cartonConflict = $this->cartonPriceConflict($proposal, $row, $multiplier);

            if ($cartonConflict !== null) {
                $conflicting[] = $proposal + $cartonConflict;
                continue;
            }

            $existing = ProductPackaging::query()
                ->where('product_id', $product->id)
                ->where('from_unit', $fromUnit)
                ->where('to_unit', $toUnit)
                ->first();
            $payload = [
                'product_id' => $product->id,
                'from_unit' => $fromUnit,
                'to_unit' => $toUnit,
                'multiplier' => round($multiplier, 4),
            ];
            $report = $proposal + $payload;

            if (!$existing instanceof ProductPackaging) {
                ProductPackaging::create($payload);
                $created[] = $report;

                continue;
            }

            if (round((float) $existing->multiplier, 4) !== round($multiplier, 4)) {
                $oldMultiplier = (float) $existing->multiplier;
                $existing->fill(['multiplier' => round($multiplier, 4)]);
                $existing->save();
                $updated[] = $report + ['old_multiplier' => $oldMultiplier];
            } else {
                $unchanged[] = $report;
            }
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'unchanged' => $unchanged,
            'conflicting' => $conflicting,
        ];
    }

    /**
     * @param  array<string, mixed>  $proposal
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    private function cartonPriceConflict(array $proposal, array $row, float $multiplier): ?array
    {
        if (($proposal['from_unit'] ?? null) !== 'CT') {
            return null;
        }

        $unitPrice = $row['price']['prezzo'] ?? null;
        $cartonPrice = $row['price']['prezzo_cartone'] ?? null;

        if ($unitPrice === null || $cartonPrice === null) {
            return null;
        }

        $expected = round((float) $unitPrice * $multiplier, 5);
        $actual = round((float) $cartonPrice, 5);

        if (abs($expected - $actual) <= 0.01) {
            return null;
        }

        return [
            'reason' => 'prezzo_cartone_non_coerente',
            'expected_carton_price' => $expected,
            'actual_carton_price' => $actual,
        ];
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @param  array{listino:string, valid_from?:string|null, valid_to?:string|null}  $options
     * @return array<string, mixed>
     */
    private function compareWithDatabase(array $parsed, array $options): array
    {
        $listinoName = trim($options['listino']) !== '' ? trim($options['listino']) : 'Scuole';
        $listino = Schema::hasTable('Listini')
            ? Listino::query()->where('nome_listino', $listinoName)->first()
            : null;

        $existingCategories = $this->loadExistingCategories();
        $existingProducts = $this->loadExistingProducts();
        $existingPivots = $this->loadExistingPivots($listino?->id);

        $categories = [
            'new' => [],
            'existing' => [],
            'ambiguous' => [],
        ];

        foreach ($parsed['categories']['parsed'] as $category) {
            $existing = $existingCategories[$category['key']] ?? null;

            if ($existing === null) {
                $categories['new'][] = $category;
            } else {
                $categories['existing'][] = $category + ['existing_id' => $existing['id']];
            }
        }

        $products = [
            'new' => [],
            'to_update' => [],
            'unchanged' => [],
        ];
        $prices = [
            'new' => [],
            'to_update' => [],
            'unchanged' => [],
            'invalid' => $parsed['prices']['invalid'],
        ];

        foreach ($parsed['unique_product_rows'] as $row) {
            $existingProduct = $existingProducts[$row['sku']] ?? null;
            $categoryId = $existingCategories[$row['category']['key']]['id'] ?? null;

            if ($existingProduct === null) {
                $products['new'][] = $this->productReportPayload($row, ['missing_local_product']);
            } else {
                $changes = $this->productChanges($row, $existingProduct, $categoryId);

                if ($changes === []) {
                    $products['unchanged'][] = $this->productReportPayload($row);
                } else {
                    $products['to_update'][] = $this->productReportPayload($row, $changes, $existingProduct['id']);
                }
            }

            $pricePayload = $this->priceReportPayload($row, $listinoName, $listino?->id, $existingProduct['id'] ?? null);

            if (($row['price']['prezzo'] ?? null) === null && !$row['price']['ordinabile']) {
                if ($listino === null || $existingProduct === null) {
                    $prices['new'][] = $pricePayload + ['changes' => ['pivot_non_esistente']];
                } else {
                    $pivot = $existingPivots[$existingProduct['id']] ?? null;
                    $prices[$pivot === null ? 'new' : 'to_update'][] = $pricePayload + [
                        'changes' => $pivot === null ? ['pivot_non_esistente'] : ['ordinabilita'],
                    ];
                }

                continue;
            }

            if ($listino === null || $existingProduct === null) {
                $prices['new'][] = $pricePayload + ['changes' => ['pivot_non_esistente']];
                continue;
            }

            $pivot = $existingPivots[$existingProduct['id']] ?? null;

            if ($pivot === null) {
                $prices['new'][] = $pricePayload + ['changes' => ['pivot_non_esistente']];
                continue;
            }

            $changes = $this->priceChanges($row, $pivot);

            if ($changes === []) {
                $prices['unchanged'][] = $pricePayload;
            } else {
                $prices['to_update'][] = $pricePayload + ['changes' => $changes];
            }
        }

        return [
            'options' => $options + [
                'listino_id' => $listino?->id,
                'listino_exists' => $listino !== null,
            ],
            'categories' => $parsed['categories'] + $categories,
            'products' => $products,
            'prices' => $prices,
        ];
    }

    /**
     * @return array<string, array{id:int, nome:string, codice:?string}>
     */
    private function loadExistingCategories(): array
    {
        if (!Schema::hasTable('Categorie')) {
            return [];
        }

        $columns = ['id', 'nome'];

        if (Schema::hasColumn('Categorie', 'codice')) {
            $columns[] = 'codice';
        }

        return Categoria::query()
            ->get($columns)
            ->mapWithKeys(fn (Categoria $categoria): array => [
                $this->normalizeCategory($categoria->nome)['key'] => [
                    'id' => (int) $categoria->id,
                    'nome' => (string) $categoria->nome,
                    'codice' => isset($categoria->codice) && $categoria->codice !== null ? (string) $categoria->codice : null,
                ],
            ])
            ->all();
    }

    /**
     * @return array<string, array{id:int, sku:string, nome:string, unita_misura:?string, categoria_key:?string, categoria_id:?int}>
     */
    private function loadExistingProducts(): array
    {
        if (!Schema::hasTable('Prodotti')) {
            return [];
        }

        $columns = ['id', 'codice', 'nome'];
        $hasCategory = Schema::hasColumn('Prodotti', 'categoria_id');

        if (Schema::hasColumn('Prodotti', 'unita_misura')) {
            $columns[] = 'unita_misura';
        }

        if ($hasCategory) {
            $columns[] = 'categoria_id';
        }

        $query = Product::withTrashed();

        if ($hasCategory) {
            $query->with('categoria:id,nome');
        }

        return $query
            ->get($columns)
            ->mapWithKeys(function (Product $product): array {
                $categoriaKey = isset($product->categoria) && $product->categoria?->nome !== null
                    ? $this->normalizeCategory($product->categoria->nome)['key']
                    : null;

                return [
                    $this->normalizeSku((string) $product->codice) => [
                        'id' => (int) $product->id,
                        'sku' => $this->normalizeSku((string) $product->codice),
                        'nome' => (string) $product->nome,
                        'unita_misura' => isset($product->unita_misura) && $product->unita_misura !== null ? (string) $product->unita_misura : null,
                        'categoria_key' => $categoriaKey,
                        'categoria_id' => isset($product->categoria_id) && $product->categoria_id !== null ? (int) $product->categoria_id : null,
                    ],
                ];
            })
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadExistingPivots(?int $listinoId): array
    {
        if ($listinoId === null || !Schema::hasTable('listino_prodotto')) {
            return [];
        }

        return DB::table('listino_prodotto')
            ->where('listino_id', $listinoId)
            ->get()
            ->mapWithKeys(fn (object $row): array => [(int) $row->product_id => (array) $row])
            ->all();
    }

    /**
     * @param  array<int, mixed>  $row
     * @param  array<string, int>  $columns
     */
    private function parsePrice(array $row, array $columns): array
    {
        $rawNet = $this->value($row, $columns, 'prezzo');
        $rawGross = $this->value($row, $columns, 'prezzo_lordo');
        $rawCarton = $this->value($row, $columns, 'prezzo_cartone');
        $rawIva = $this->value($row, $columns, 'iva');
        $rawDiscount = $this->value($row, $columns, 'sconto');
        $unit = $this->normalizeUnit($this->cellString($this->value($row, $columns, 'unita_misura')));

        $net = $this->parseDecimal($rawNet);
        $gross = $this->parseDecimal($rawGross);
        $carton = $this->parseDecimal($rawCarton);
        $iva = $this->parseDecimal($rawIva);
        $discount = $this->parseDiscount($rawDiscount);
        $netColumnExists = isset($columns['prezzo']);

        $sourceValue = $this->cellString($netColumnExists ? $rawNet : $rawGross);
        $price = null;
        $reason = null;

        if ($netColumnExists) {
            if (!$net['valid']) {
                $reason = $this->priceReason($net);
            } elseif ((float) $net['value'] <= 0.0) {
                $reason = 'prezzo_zero';
            } else {
                $price = round((float) $net['value'], 5);
            }
        } elseif ($gross['valid'] && (float) $gross['value'] > 0.0) {
            $price = round((float) $gross['value'] * (1 - ((float) $discount['equivalent'] / 100)), 5);
            $sourceValue = $this->cellString($rawGross);
        } elseif (!$gross['valid']) {
            $reason = $this->priceReason($gross);
        } else {
            $reason = 'prezzo_zero';
        }

        return [
            'prezzo' => $price,
            'prezzo_lordo' => $gross['valid'] ? round((float) $gross['value'], 5) : $price,
            'prezzo_cartone' => $carton['valid'] ? round((float) $carton['value'], 5) : null,
            'iva_percentuale' => $iva['valid'] ? round((float) $iva['value'], 5) : null,
            'sconto_percentuale' => $discount['valid'] ? round((float) $discount['equivalent'], 5) : null,
            'sconto_sorgente' => $discount['source'],
            'sconto_componenti' => $discount['parts'],
            'sconto_valido' => $discount['valid'],
            'ordinabile' => $price !== null && $price > 0,
            'motivo_non_ordinabile' => $price !== null && $price > 0 ? null : $reason,
            'source_value' => $sourceValue,
            'prezzo_sorgente' => $netColumnExists && $net['valid'] ? round((float) $net['value'], 5) : ($gross['valid'] ? round((float) $gross['value'], 5) : null),
            'unita_prezzo_sorgente' => $unit,
        ];
    }

    /**
     * @param  array<int, mixed>  $row
     * @param  array<string, int>  $columns
     * @param  array{key:string, name:string, code:?string, source:string}  $category
     * @return array{valid:array<int, array<string, mixed>>, incomplete:array<int, array<string, mixed>>, tassativi:array<int, array<string, mixed>>, text:?string, tassativo:bool}
     */
    private function parsePackaging(array $row, array $columns, int $line, string $sku, string $description, array $category): array
    {
        $text = $this->normalizeSpaces($this->cellString($this->value($row, $columns, 'confezionamento')));
        $imballo = $this->parseDecimal($this->value($row, $columns, 'imballo'));
        $subimballo = $this->parseDecimal($this->value($row, $columns, 'subimballo'));
        $tassativoValue = $this->parseDecimal($this->value($row, $columns, 'tassativo'));
        $tassativo = $tassativoValue['valid'] && (float) $tassativoValue['value'] === -1.0;

        $hasPackagingColumns = isset($columns['imballo'])
            || isset($columns['subimballo'])
            || isset($columns['confezionamento'])
            || isset($columns['tassativo']);

        $valid = [];
        $incomplete = [];
        $tassativi = [];

        if (!$hasPackagingColumns) {
            return [
                'valid' => [],
                'incomplete' => [],
                'tassativi' => [],
                'text' => $text !== '' ? $text : null,
                'tassativo' => false,
            ];
        }

        if ($tassativo) {
            $tassativi[] = $this->anomaly($line, $sku, $description, $category['name'], '-1', 'tassativo_blocca_unita_alternative');

            return [
                'valid' => [],
                'incomplete' => [],
                'tassativi' => $tassativi,
                'text' => $text !== '' ? $text : null,
                'tassativo' => true,
            ];
        }

        if ($subimballo['valid'] && (float) $subimballo['value'] > 1.0) {
            $valid[] = [
                'line' => $line,
                'sku' => $sku,
                'description' => $description,
                'category' => $category['name'],
                'from_unit' => 'CF',
                'to_unit' => 'NR',
                'multiplier' => round((float) $subimballo['value'], 5),
                'source_field' => 'subimballo',
                'source_value' => $subimballo['source'],
            ];
        }

        if ($imballo['valid'] && (float) $imballo['value'] > 1.0) {
            $valid[] = [
                'line' => $line,
                'sku' => $sku,
                'description' => $description,
                'category' => $category['name'],
                'from_unit' => 'CT',
                'to_unit' => 'CF',
                'multiplier' => round((float) $imballo['value'], 5),
                'source_field' => 'imballo',
                'source_value' => $imballo['source'],
            ];
        }

        if ($valid === [] && $text !== '') {
            $fromText = $this->parsePackagingText($text, $line, $sku, $description, $category);
            $valid = $fromText['valid'];
            $incomplete = $fromText['incomplete'];
        }

        $imballoIsOne = $imballo['valid'] && (float) $imballo['value'] === 1.0;
        $subimballoIsOne = $subimballo['valid'] && (float) $subimballo['value'] === 1.0;

        if ($valid === [] && $imballoIsOne && $subimballoIsOne) {
            $incomplete[] = $this->anomaly(
                $line,
                $sku,
                $description,
                $category['name'],
                'imballo=1; subimballo=1',
                'imballo_subimballo_non_significativi'
            );
        } elseif ($valid === [] && $incomplete === [] && $text !== '') {
            $incomplete[] = $this->anomaly(
                $line,
                $sku,
                $description,
                $category['name'],
                $text,
                'confezionamento_testuale_non_convertito'
            );
        }

        return [
            'valid' => $valid,
            'incomplete' => $incomplete,
            'tassativi' => $tassativi,
            'text' => $text !== '' ? $text : null,
            'tassativo' => false,
        ];
    }

    /**
     * @param  array{key:string, name:string, code:?string, source:string}  $category
     * @return array{valid:array<int, array<string, mixed>>, incomplete:array<int, array<string, mixed>>}
     */
    private function parsePackagingText(string $text, int $line, string $sku, string $description, array $category): array
    {
        $normalized = $this->normalizeTextKey($text);
        $patterns = [
            ['pattern' => '/([0-9]+(?:[,.][0-9]+)?)\s*(?:pz|pezzi|pezzo|nr)\s*(?:per\s+)?(?:confezione|confez|cf)\b/u', 'from' => 'CF', 'to' => 'NR'],
            ['pattern' => '/(?:cf|confezione|confez)\s*([0-9]+(?:[,.][0-9]+)?)\s*(?:pz|pezzi|pezzo|nr)\b/u', 'from' => 'CF', 'to' => 'NR'],
            ['pattern' => '/([0-9]+(?:[,.][0-9]+)?)\s*(?:cf|conf|confez|confezioni|confezione)\s*(?:per\s+)?(?:cartone|ct|collo)\b/u', 'from' => 'CT', 'to' => 'CF'],
            ['pattern' => '/([0-9]+(?:[,.][0-9]+)?)\s*(?:pz|pezzi|pezzo|nr|rt|rotoli|rotolo)\s*(?:per\s+)?(?:cartone|ct|collo)\b/u', 'from' => 'CT', 'to' => 'NR'],
        ];
        $valid = [];
        $seen = [];
        $nonSignificant = false;

        foreach ($patterns as $definition) {
            if (preg_match_all($definition['pattern'], $normalized, $matches) !== false) {
                foreach ($matches[1] ?? [] as $match) {
                    $multiplier = $this->parseDecimal($match);

                    if (!$multiplier['valid']) {
                        continue;
                    }

                    if ((float) $multiplier['value'] <= 1.0) {
                        $nonSignificant = true;
                        continue;
                    }

                    $key = $definition['from'] . ':' . $definition['to'] . ':' . round((float) $multiplier['value'], 5);

                    if (isset($seen[$key])) {
                        continue;
                    }

                    $seen[$key] = true;
                    $valid[] = [
                        'line' => $line,
                        'sku' => $sku,
                        'description' => $description,
                        'category' => $category['name'],
                        'from_unit' => $definition['from'],
                        'to_unit' => $definition['to'],
                        'multiplier' => round((float) $multiplier['value'], 5),
                        'source_field' => 'confezionamento',
                        'source_value' => $text,
                    ];
                }
            }
        }

        if ($valid !== []) {
            return ['valid' => $valid, 'incomplete' => []];
        }

        return [
            'valid' => [],
            'incomplete' => [
                $this->anomaly(
                    $line,
                    $sku,
                    $description,
                    $category['name'],
                    $text,
                    $nonSignificant ? 'confezionamento_non_significativo' : 'confezionamento_testuale_non_convertito'
                ),
            ],
        ];
    }

    /**
     * @return array{valid:bool, value:?float, source:string, reason:?string, empty:bool}
     */
    private function parseDecimal(mixed $value): array
    {
        if ($value === null) {
            return ['valid' => false, 'value' => null, 'source' => '', 'reason' => 'empty', 'empty' => true];
        }

        if (is_int($value) || is_float($value)) {
            return ['valid' => true, 'value' => (float) $value, 'source' => (string) $value, 'reason' => null, 'empty' => false];
        }

        $source = trim((string) $value);

        if ($source === '') {
            return ['valid' => false, 'value' => null, 'source' => $source, 'reason' => 'empty', 'empty' => true];
        }

        $upper = strtoupper($source);

        if ($upper === '#REF!' || $upper === '#REF') {
            return ['valid' => false, 'value' => null, 'source' => $source, 'reason' => 'ref', 'empty' => false];
        }

        if ($source === '---') {
            return ['valid' => false, 'value' => null, 'source' => $source, 'reason' => 'dashes', 'empty' => false];
        }

        $number = str_replace(["\xc2\xa0", ' ', '%'], '', $source);
        $number = preg_replace('/[^\d,.\-+]/', '', $number) ?? '';

        if ($number === '' || $number === '-' || $number === '+') {
            return ['valid' => false, 'value' => null, 'source' => $source, 'reason' => 'non_numeric', 'empty' => false];
        }

        $lastComma = strrpos($number, ',');
        $lastDot = strrpos($number, '.');

        if ($lastComma !== false && $lastDot !== false) {
            if ($lastComma > $lastDot) {
                $number = str_replace('.', '', $number);
                $number = str_replace(',', '.', $number);
            } else {
                $number = str_replace(',', '', $number);
            }
        } elseif ($lastComma !== false) {
            $number = str_replace('.', '', $number);
            $number = str_replace(',', '.', $number);
        } else {
            $number = str_replace(',', '', $number);
        }

        if (!is_numeric($number)) {
            return ['valid' => false, 'value' => null, 'source' => $source, 'reason' => 'non_numeric', 'empty' => false];
        }

        return ['valid' => true, 'value' => (float) $number, 'source' => $source, 'reason' => null, 'empty' => false];
    }

    /**
     * @return array{source:string, parts:array<int, float>, equivalent:float, valid:bool}
     */
    private function parseDiscount(mixed $value): array
    {
        $source = trim((string) ($value ?? ''));

        if ($source === '') {
            return ['source' => $source, 'parts' => [], 'equivalent' => 0.0, 'valid' => true];
        }

        $parts = preg_split('/\+/', $source) ?: [];
        $discounts = [];
        $factor = 1.0;

        foreach ($parts as $part) {
            $parsed = $this->parseDecimal($part);

            if (!$parsed['valid']) {
                return ['source' => $source, 'parts' => [], 'equivalent' => 0.0, 'valid' => false];
            }

            $discount = (float) $parsed['value'];
            $discounts[] = $discount;
            $factor *= (1 - ($discount / 100));
        }

        return [
            'source' => $source,
            'parts' => $discounts,
            'equivalent' => (1 - $factor) * 100,
            'valid' => true,
        ];
    }

    private function priceReason(array $parsed): string
    {
        return match ($parsed['reason']) {
            'empty' => 'prezzo_vuoto',
            'ref' => 'prezzo_ref',
            'dashes' => 'prezzo_trattini',
            default => 'prezzo_non_numerico',
        };
    }

    /**
     * @return array{key:string, name:string, code:?string, source:string}
     */
    private function normalizeCategory(string $value): array
    {
        $source = $this->normalizeSpaces($value);
        $name = $source;
        $code = null;

        if (preg_match('/^([0-9]+(?:\.[0-9]+)*)\s+\1\s+(.+)$/', $source, $matches) === 1) {
            $code = $matches[1];
            $name = $matches[2];
        } elseif (preg_match('/^([0-9]+(?:\.[0-9]+)*)\s+(.+)$/', $source, $matches) === 1) {
            $code = $matches[1];
            $name = $matches[2];
        }

        $name = $this->normalizeSpaces($name);

        return [
            'key' => $this->normalizeTextKey($name),
            'name' => $name,
            'code' => $code,
            'source' => $source,
        ];
    }

    private function normalizeSku(string $value): string
    {
        $value = $this->normalizeSpaces($value);

        if (str_starts_with($value, "'") && strlen($value) > 1) {
            $value = substr($value, 1);
        }

        return $value;
    }

    private function normalizeUnit(string $value): ?string
    {
        $value = strtoupper($this->normalizeSpaces($value));

        return $value !== '' ? $value : null;
    }

    private function normalizeTextKey(string $value): string
    {
        $value = Str::of($this->normalizeSpaces($value))
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish()
            ->toString();

        return $value;
    }

    private function normalizeSpaces(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }

    /**
     * @param  array<int, mixed>  $row
     */
    private function rowIsEmpty(array $row): bool
    {
        foreach ($row as $value) {
            if ($this->cellString($value) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, mixed>  $row
     * @param  array<string, int>  $columns
     */
    private function value(array $row, array $columns, string $canonical): mixed
    {
        if (!isset($columns[$canonical])) {
            return null;
        }

        return $row[$columns[$canonical]] ?? null;
    }

    private function cellString(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_float($value) && floor($value) === $value) {
            return (string) (int) $value;
        }

        return trim((string) $value);
    }

    /**
     * @param  array<int, mixed>  $row
     * @param  array<string, int>  $columns
     * @return array<string, mixed>
     */
    private function rawRowByCanonical(array $row, array $columns): array
    {
        $payload = [];

        foreach ($columns as $canonical => $index) {
            $payload[$canonical] = $row[$index] ?? null;
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function productSignature(array $row): string
    {
        return md5(json_encode([
            'description' => $this->normalizeTextKey($row['description']),
            'category' => $row['category']['key'],
            'unit' => $row['unit'],
            'prezzo' => $row['price']['prezzo'],
            'prezzo_lordo' => $row['price']['prezzo_lordo'],
            'prezzo_cartone' => $row['price']['prezzo_cartone'],
            'iva' => $row['price']['iva_percentuale'],
            'sconto' => $row['price']['sconto_percentuale'],
            'ordinabile' => $row['price']['ordinabile'],
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function conflictRowPayload(array $row): array
    {
        return [
            'line' => $row['line'],
            'sku' => $row['sku'],
            'description' => $row['description'],
            'category' => $row['category']['name'],
            'unit' => $row['unit'],
            'prezzo' => $row['price']['prezzo'],
            'prezzo_cartone' => $row['price']['prezzo_cartone'],
        ];
    }

    /**
     * @param  array<string, mixed>  $first
     * @param  array<string, mixed>  $current
     * @return array<int, string>
     */
    private function duplicateConflictReasons(array $first, array $current): array
    {
        $reasons = [];

        foreach ([
            'description' => 'descrizione',
            'unit' => 'unita',
        ] as $field => $reason) {
            if (($first[$field] ?? null) !== ($current[$field] ?? null)) {
                $reasons[] = $reason;
            }
        }

        if ($first['category']['key'] !== $current['category']['key']) {
            $reasons[] = 'categoria';
        }

        if (($first['price']['prezzo'] ?? null) !== ($current['price']['prezzo'] ?? null)) {
            $reasons[] = 'prezzo';
        }

        if (($first['price']['prezzo_cartone'] ?? null) !== ($current['price']['prezzo_cartone'] ?? null)) {
            $reasons[] = 'prezzo_cartone';
        }

        return $reasons !== [] ? $reasons : ['dati_non_identici'];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $existing
     * @return array<int, string>
     */
    private function productChanges(array $row, array $existing, ?int $categoryId): array
    {
        $changes = [];

        if ($this->normalizeTextKey($existing['nome']) !== $this->normalizeTextKey($row['description'])) {
            $changes[] = 'nome';
        }

        if (($existing['unita_misura'] ?? null) !== ($row['unit'] ?? null)) {
            $changes[] = 'unita_misura';
        }

        if ($categoryId !== null && ($existing['categoria_id'] ?? null) !== $categoryId) {
            $changes[] = 'categoria';
        } elseif ($categoryId === null && ($existing['categoria_key'] ?? null) !== $row['category']['key']) {
            $changes[] = 'categoria_da_creare';
        }

        return $changes;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function productReportPayload(array $row, array $changes = [], ?int $existingId = null): array
    {
        return [
            'line' => $row['line'],
            'sku' => $row['sku'],
            'description' => $row['description'],
            'category' => $row['category']['name'],
            'unit' => $row['unit'],
            'existing_id' => $existingId,
            'changes' => $changes,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function priceReportPayload(array $row, string $listinoName, ?int $listinoId, ?int $productId): array
    {
        return [
            'line' => $row['line'],
            'sku' => $row['sku'],
            'description' => $row['description'],
            'category' => $row['category']['name'],
            'listino' => $listinoName,
            'listino_id' => $listinoId,
            'product_id' => $productId,
            'prezzo' => $row['price']['prezzo'],
            'prezzo_lordo' => $row['price']['prezzo_lordo'],
            'prezzo_cartone' => $row['price']['prezzo_cartone'],
            'prezzo_sorgente' => $row['price']['prezzo_sorgente'],
            'unita_prezzo_sorgente' => $row['price']['unita_prezzo_sorgente'],
            'ordinabile' => $row['price']['ordinabile'],
            'motivo_non_ordinabile' => $row['price']['motivo_non_ordinabile'],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $pivot
     * @return array<int, string>
     */
    private function priceChanges(array $row, array $pivot): array
    {
        $checks = [
            'prezzo_lordo' => $row['price']['prezzo_lordo'],
            'prezzo' => $row['price']['prezzo'],
            'iva_percentuale' => $row['price']['iva_percentuale'],
            'sconto_percentuale' => $row['price']['sconto_percentuale'],
            'prezzo_sorgente' => $row['price']['prezzo_sorgente'],
        ];
        $changes = [];

        foreach ($checks as $field => $expected) {
            if ($expected === null) {
                continue;
            }

            if (!isset($pivot[$field]) || round((float) $pivot[$field], 5) !== round((float) $expected, 5)) {
                $changes[] = $field;
            }
        }

        if (($pivot['unita_prezzo_sorgente'] ?? null) !== $row['price']['unita_prezzo_sorgente']) {
            $changes[] = 'unita_prezzo_sorgente';
        }

        if (array_key_exists('ordinabile', $pivot) && (bool) $pivot['ordinabile'] !== (bool) $row['price']['ordinabile']) {
            $changes[] = 'ordinabile';
        }

        if (($pivot['motivo_non_ordinabile'] ?? null) !== $row['price']['motivo_non_ordinabile']) {
            $changes[] = 'motivo_non_ordinabile';
        }

        return array_values(array_unique($changes));
    }

    private function anomaly(int $line, ?string $sku, ?string $description, ?string $category, mixed $sourceValue, string $reason): array
    {
        return [
            'line' => $line,
            'sku' => $sku,
            'description' => $description,
            'category' => $category,
            'source_value' => $sourceValue,
            'reason' => $reason,
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function withSummary(array $result): array
    {
        $result['summary'] = [
            'rows_total' => $result['rows_total'],
            'product_rows' => count($result['product_rows']),
            'section_rows' => count($result['section_rows']),
            'categorie_nuove' => count($result['categories']['new'] ?? []),
            'categorie_esistenti' => count($result['categories']['existing'] ?? []),
            'prodotti_nuovi' => count($result['products']['new'] ?? []),
            'prodotti_da_aggiornare' => count($result['products']['to_update'] ?? []),
            'prodotti_invariati' => count($result['products']['unchanged'] ?? []),
            'prezzi_nuovi' => count($result['prices']['new'] ?? []),
            'prezzi_da_aggiornare' => count($result['prices']['to_update'] ?? []),
            'prezzi_invariati' => count($result['prices']['unchanged'] ?? []),
            'prodotti_ordinabili' => collect($result['unique_product_rows'])->where('price.ordinabile', true)->count(),
            'prodotti_non_ordinabili' => count($result['prices']['invalid'] ?? []),
            'packaging_validi' => count($result['packaging']['valid'] ?? []),
            'packaging_incompleti' => count($result['packaging']['incomplete'] ?? []),
            'packaging_conflittuali' => count($result['packaging']['conflicting'] ?? []),
            'duplicati_identici' => count($result['duplicates']['identical'] ?? []),
            'duplicati_conflittuali' => count($result['duplicates']['conflicting'] ?? []),
            'righe_ignorate' => count($result['ignored_rows'] ?? []),
            'errori' => count($result['errors'] ?? []),
        ];

        foreach ($result['write_summary'] ?? [] as $key => $value) {
            $result['summary'][$key] = $value;
        }

        return $result;
    }
}
