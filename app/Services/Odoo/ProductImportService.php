<?php

declare(strict_types=1);

namespace App\Services\Odoo;

use App\Models\Categoria;
use App\Models\Product;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ProductImportService
{
    private const MODEL = 'product.template';
    private const DEFAULT_CHUNK_SIZE = 200;
    private const FIELDS = [
        'id',
        'name',
        'default_code',
        'categ_id',
        'description',
        'uom_id',
        'write_date',
        'active',
    ];

    private readonly OdooClient $client;

    public function __construct(?OdooClient $client = null)
    {
        $this->client = $client ?? OdooClient::fromConfig();
    }

    /**
     * @return array{
     *     imported:int,
     *     updated:int,
     *     processed:int,
     *     errors:int,
     *     invalid_categories:int,
     *     skipped:int,
     *     chunks:int
     * }
     */
    public function import(?CarbonInterface $since = null, int $chunkSize = self::DEFAULT_CHUNK_SIZE): array
    {
        $chunkSize = max(1, $chunkSize);
        $stats = $this->initialStats();
        $offset = 0;
        $productIndex = $this->loadProductIndex();
        $categoryIdsByOdooId = $this->loadCategoryIdsByOdooId();

        Log::info('Odoo product import started', [
            'chunk_size' => $chunkSize,
            'since' => $since?->format('Y-m-d H:i:s'),
            'known_products' => count($productIndex['by_code']),
            'known_categories' => count($categoryIdsByOdooId),
        ]);

        if ($categoryIdsByOdooId === []) {
            Log::warning('Odoo product import started without synced categories', [
                'offset' => $offset,
            ]);
        }

        try {
            while (true) {
                $records = $this->fetchChunk($offset, $chunkSize, $since);
                $recordCount = count($records);

                if ($recordCount === 0) {
                    break;
                }

                $stats['chunks']++;

                Log::info('Odoo product chunk fetched', [
                    'offset' => $offset,
                    'records' => $recordCount,
                ]);

                $chunkPayload = $this->buildChunkPayload($records, $offset, $productIndex, $categoryIdsByOdooId);

                $this->persistChunk($chunkPayload, $offset);
                $this->refreshProductIndex($productIndex, $chunkPayload['persisted_odoo_ids']);
                $this->mergeStats($stats, $chunkPayload);

                Log::info('Odoo product chunk persisted', [
                    'offset' => $offset,
                    'processed' => $chunkPayload['processed'],
                    'imported' => $chunkPayload['imported'],
                    'updated' => $chunkPayload['updated'],
                    'skipped' => $chunkPayload['skipped'],
                    'invalid_categories' => $chunkPayload['invalid_categories'],
                    'errors' => $chunkPayload['errors'],
                ]);

                $offset += $recordCount;

                if ($recordCount < $chunkSize) {
                    break;
                }
            }

            Log::info('Odoo product import completed', $stats + [
                'chunk_size' => $chunkSize,
                'since' => $since?->format('Y-m-d H:i:s'),
            ]);

            return $stats;
        } catch (Throwable $exception) {
            Log::error('Odoo product import failed', [
                'offset' => $offset,
                'chunk_size' => $chunkSize,
                'since' => $since?->format('Y-m-d H:i:s'),
                'exception' => $exception,
            ]);

            throw $exception;
        }
    }

    /**
     * @return array{
     *     imported:int,
     *     updated:int,
     *     processed:int,
     *     errors:int,
     *     invalid_categories:int,
     *     skipped:int,
     *     chunks:int
     * }
     */
    public function importAll(?CarbonInterface $since = null, int $chunkSize = self::DEFAULT_CHUNK_SIZE): array
    {
        return $this->import($since, $chunkSize);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchChunk(int $offset, int $chunkSize, ?CarbonInterface $since): array
    {
        return $this->client->searchRead(
            self::MODEL,
            $this->buildDomain($since),
            self::FIELDS,
            [
                'limit' => $chunkSize,
                'offset' => $offset,
                'order' => 'write_date asc, id asc',
            ]
        );
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function buildDomain(?CarbonInterface $since): array
    {
        if ($since === null) {
            return [];
        }

        return [
            ['write_date', '>', $since->format('Y-m-d H:i:s')],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $records
     * @param  array{
     *     by_odoo_id:array<int, array{id:int, odoo_id:?int, codice:string, categoria_id:?int, disponibile:bool, deleted_at:?string}>,
     *     by_code:array<string, array{id:int, odoo_id:?int, codice:string, categoria_id:?int, disponibile:bool, deleted_at:?string}>
     * }  $productIndex
     * @param  array<int, int>  $categoryIdsByOdooId
     * @return array{
     *     update_rows:array<int, array<string, mixed>>,
     *     insert_rows:array<int, array<string, mixed>>,
     *     persisted_odoo_ids:array<int, int>,
     *     imported:int,
     *     updated:int,
     *     processed:int,
     *     errors:int,
     *     invalid_categories:int,
     *     skipped:int
     * }
     */
    private function buildChunkPayload(array $records, int $offset, array $productIndex, array $categoryIdsByOdooId): array
    {
        $updateRowsById = [];
        $insertRowsByOdooId = [];
        $persistedOdooIds = [];
        $seenOdooIds = [];
        $seenCodes = [];
        $stats = [
            'imported' => 0,
            'updated' => 0,
            'processed' => 0,
            'errors' => 0,
            'invalid_categories' => 0,
            'skipped' => 0,
        ];

        foreach ($records as $record) {
            $normalized = $this->normalizeRecord($record, $offset);
            $stats['errors'] += $normalized['errors'];

            $product = $normalized['product'];
            if ($product === null) {
                $stats['skipped']++;
                continue;
            }

            if (isset($seenOdooIds[$product['odoo_id']])) {
                $stats['errors']++;
                $stats['skipped']++;

                Log::warning('Duplicate Odoo product found in the same chunk', [
                    'offset' => $offset,
                    'odoo_id' => $product['odoo_id'],
                ]);

                continue;
            }

            if (isset($seenCodes[$product['code']]) && $seenCodes[$product['code']] !== $product['odoo_id']) {
                $stats['errors']++;
                $stats['skipped']++;

                Log::warning('Duplicate product code found in the same chunk', [
                    'offset' => $offset,
                    'code' => $product['code'],
                    'odoo_id' => $product['odoo_id'],
                ]);

                continue;
            }

            $resolution = $this->resolveExistingProduct($product, $productIndex, $offset);
            if ($resolution === null) {
                $stats['errors']++;
                $stats['skipped']++;

                continue;
            }

            $existingProduct = $resolution['existing'];
            $categoryResolution = $this->resolveCategoryId($product, $existingProduct, $categoryIdsByOdooId, $offset);

            $stats['errors'] += $categoryResolution['errors'];
            $stats['invalid_categories'] += $categoryResolution['invalid_categories'];

            $categoryId = $categoryResolution['category_id'];
            if ($categoryId === null) {
                $stats['skipped']++;
                continue;
            }

            $row = [
                'odoo_id' => $product['odoo_id'],
                'nome' => $product['name'],
                'codice' => $product['code'],
                'categoria_id' => $categoryId,
                'unita_misura' => $product['unit_of_measure'],
                'descrizione' => $product['description'],
                'disponibile' => $product['active'] ?? ($existingProduct['disponibile'] ?? true),
                'odoo_write_date' => $product['write_date'],
                'deleted_at' => null,
                'updated_at' => now(),
            ];

            if ($resolution['mode'] === 'update') {
                $row['id'] = $resolution['id'];
                $updateRowsById[$resolution['id']] = $row;
                $stats['updated']++;
            } else {
                $row['created_at'] = $row['updated_at'];
                $insertRowsByOdooId[$product['odoo_id']] = $row;
                $stats['imported']++;
            }

            $persistedOdooIds[$product['odoo_id']] = $product['odoo_id'];
            $seenOdooIds[$product['odoo_id']] = true;
            $seenCodes[$product['code']] = $product['odoo_id'];
            $stats['processed']++;
        }

        return [
            'update_rows' => array_values($updateRowsById),
            'insert_rows' => array_values($insertRowsByOdooId),
            'persisted_odoo_ids' => array_values($persistedOdooIds),
            'imported' => $stats['imported'],
            'updated' => $stats['updated'],
            'processed' => $stats['processed'],
            'errors' => $stats['errors'],
            'invalid_categories' => $stats['invalid_categories'],
            'skipped' => $stats['skipped'],
        ];
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array{
     *     product:?array{
     *         odoo_id:int,
     *         name:string,
     *         code:string,
     *         category_odoo_id:?int,
     *         description:?string,
     *         unit_of_measure:?string,
     *         write_date:?string,
     *         active:?bool
     *     },
     *     errors:int
     * }
     */
    private function normalizeRecord(array $record, int $offset): array
    {
        $odooId = $this->normalizePositiveInt($record['id'] ?? null);
        if ($odooId === null) {
            Log::error('Odoo product skipped because id is invalid', [
                'offset' => $offset,
                'record' => $record,
            ]);

            return [
                'product' => null,
                'errors' => 1,
            ];
        }

        $name = trim((string) ($record['name'] ?? ''));
        if ($name === '') {
            Log::error('Odoo product skipped because name is missing', [
                'offset' => $offset,
                'odoo_id' => $odooId,
            ]);

            return [
                'product' => null,
                'errors' => 1,
            ];
        }

        $code = trim((string) ($record['default_code'] ?? ''));
        if ($code === '') {
            Log::warning('Odoo product skipped because default_code is missing', [
                'offset' => $offset,
                'odoo_id' => $odooId,
                'name' => $name,
            ]);

            return [
                'product' => null,
                'errors' => 1,
            ];
        }

        $rawWriteDate = $record['write_date'] ?? null;
        $writeDate = $this->normalizeWriteDate($rawWriteDate);
        $errors = 0;

        if (!$this->isEmptyValue($rawWriteDate) && $writeDate === null) {
            $errors++;

            Log::warning('Odoo product has an invalid write_date', [
                'offset' => $offset,
                'odoo_id' => $odooId,
                'write_date' => $rawWriteDate,
            ]);
        }

        $rawCategory = $record['categ_id'] ?? null;
        $categoryOdooId = $this->extractMany2OneId($rawCategory);

        if (!$this->isEmptyMany2One($rawCategory) && $categoryOdooId === null) {
            $errors++;

            Log::warning('Odoo product has an invalid category payload', [
                'offset' => $offset,
                'odoo_id' => $odooId,
                'categ_id' => $rawCategory,
            ]);
        }

        return [
            'product' => [
                'odoo_id' => $odooId,
                'name' => $name,
                'code' => $code,
                'category_odoo_id' => $categoryOdooId,
                'description' => $this->normalizeNullableString($record['description'] ?? null),
                'unit_of_measure' => $this->extractMany2OneLabel($record['uom_id'] ?? null),
                'write_date' => $writeDate,
                'active' => $this->normalizeOptionalBool($record, 'active'),
            ],
            'errors' => $errors,
        ];
    }

    /**
     * @param  array{
     *     odoo_id:int,
     *     name:string,
     *     code:string,
     *     category_odoo_id:?int,
     *     description:?string,
     *     unit_of_measure:?string,
     *     write_date:?string,
     *     active:?bool
     * }  $product
     * @param  array{
     *     by_odoo_id:array<int, array{id:int, odoo_id:?int, codice:string, categoria_id:?int, disponibile:bool, deleted_at:?string}>,
     *     by_code:array<string, array{id:int, odoo_id:?int, codice:string, categoria_id:?int, disponibile:bool, deleted_at:?string}>
     * }  $productIndex
     * @return array{mode:string, id:?int, existing:?array{id:int, odoo_id:?int, codice:string, categoria_id:?int, disponibile:bool, deleted_at:?string}}|null
     */
    private function resolveExistingProduct(array $product, array $productIndex, int $offset): ?array
    {
        $existingByOdooId = $productIndex['by_odoo_id'][$product['odoo_id']] ?? null;
        $existingByCode = $productIndex['by_code'][$product['code']] ?? null;

        if ($existingByOdooId !== null) {
            if ($existingByCode !== null && $existingByCode['id'] !== $existingByOdooId['id']) {
                Log::error('Odoo product skipped because code is already assigned to another local product', [
                    'offset' => $offset,
                    'odoo_id' => $product['odoo_id'],
                    'code' => $product['code'],
                    'existing_product_id' => $existingByOdooId['id'],
                    'conflicting_product_id' => $existingByCode['id'],
                ]);

                return null;
            }

            return [
                'mode' => 'update',
                'id' => $existingByOdooId['id'],
                'existing' => $existingByOdooId,
            ];
        }

        if ($existingByCode === null) {
            return [
                'mode' => 'insert',
                'id' => null,
                'existing' => null,
            ];
        }

        if ($existingByCode['odoo_id'] !== null && $existingByCode['odoo_id'] !== $product['odoo_id']) {
            Log::error('Odoo product skipped because code is linked to a different Odoo product', [
                'offset' => $offset,
                'odoo_id' => $product['odoo_id'],
                'code' => $product['code'],
                'existing_product_id' => $existingByCode['id'],
                'existing_odoo_id' => $existingByCode['odoo_id'],
            ]);

            return null;
        }

        return [
            'mode' => 'update',
            'id' => $existingByCode['id'],
            'existing' => $existingByCode,
        ];
    }

    /**
     * @param  array{
     *     odoo_id:int,
     *     name:string,
     *     code:string,
     *     category_odoo_id:?int,
     *     description:?string,
     *     unit_of_measure:?string,
     *     write_date:?string,
     *     active:?bool
     * }  $product
     * @param  array{id:int, odoo_id:?int, codice:string, categoria_id:?int, disponibile:bool, deleted_at:?string}|null  $existingProduct
     * @param  array<int, int>  $categoryIdsByOdooId
     * @return array{category_id:?int, errors:int, invalid_categories:int}
     */
    private function resolveCategoryId(array $product, ?array $existingProduct, array $categoryIdsByOdooId, int $offset): array
    {
        $categoryOdooId = $product['category_odoo_id'];

        if ($categoryOdooId !== null) {
            $categoryId = $categoryIdsByOdooId[$categoryOdooId] ?? null;

            if ($categoryId !== null) {
                return [
                    'category_id' => $categoryId,
                    'errors' => 0,
                    'invalid_categories' => 0,
                ];
            }

            Log::warning('Odoo product category is not synced locally', [
                'offset' => $offset,
                'odoo_id' => $product['odoo_id'],
                'code' => $product['code'],
                'category_odoo_id' => $categoryOdooId,
            ]);
        } else {
            Log::warning('Odoo product has no category reference', [
                'offset' => $offset,
                'odoo_id' => $product['odoo_id'],
                'code' => $product['code'],
            ]);
        }

        if ($existingProduct !== null && $existingProduct['categoria_id'] !== null) {
            return [
                'category_id' => $existingProduct['categoria_id'],
                'errors' => 1,
                'invalid_categories' => 1,
            ];
        }

        Log::error('Odoo product skipped because no valid local category could be resolved', [
            'offset' => $offset,
            'odoo_id' => $product['odoo_id'],
            'code' => $product['code'],
            'category_odoo_id' => $categoryOdooId,
        ]);

        return [
            'category_id' => null,
            'errors' => 1,
            'invalid_categories' => 1,
        ];
    }

    /**
     * @param  array{
     *     update_rows:array<int, array<string, mixed>>,
     *     insert_rows:array<int, array<string, mixed>>,
     *     persisted_odoo_ids:array<int, int>,
     *     imported:int,
     *     updated:int,
     *     processed:int,
     *     errors:int,
     *     invalid_categories:int,
     *     skipped:int
     * }  $chunkPayload
     */
    private function persistChunk(array $chunkPayload, int $offset): void
    {
        if ($chunkPayload['update_rows'] === [] && $chunkPayload['insert_rows'] === []) {
            return;
        }

        try {
            DB::transaction(static function () use ($chunkPayload): void {
                if ($chunkPayload['update_rows'] !== []) {
                    Product::query()->upsert(
                        $chunkPayload['update_rows'],
                        ['id'],
                        [
                            'odoo_id',
                            'nome',
                            'codice',
                            'categoria_id',
                            'unita_misura',
                            'descrizione',
                            'disponibile',
                            'odoo_write_date',
                            'deleted_at',
                            'updated_at',
                        ]
                    );
                }

                if ($chunkPayload['insert_rows'] !== []) {
                    Product::query()->upsert(
                        $chunkPayload['insert_rows'],
                        ['odoo_id'],
                        [
                            'nome',
                            'codice',
                            'categoria_id',
                            'unita_misura',
                            'descrizione',
                            'disponibile',
                            'odoo_write_date',
                            'deleted_at',
                            'updated_at',
                        ]
                    );
                }
            });
        } catch (Throwable $exception) {
            Log::error('Odoo product chunk upsert failed', [
                'offset' => $offset,
                'update_rows' => count($chunkPayload['update_rows']),
                'insert_rows' => count($chunkPayload['insert_rows']),
                'exception' => $exception,
            ]);

            throw $exception;
        }
    }

    /**
     * @param  array{
     *     by_odoo_id:array<int, array{id:int, odoo_id:?int, codice:string, categoria_id:?int, disponibile:bool, deleted_at:?string}>,
     *     by_code:array<string, array{id:int, odoo_id:?int, codice:string, categoria_id:?int, disponibile:bool, deleted_at:?string}>
     * }  $productIndex
     * @param  array<int, int>  $persistedOdooIds
     */
    private function refreshProductIndex(array &$productIndex, array $persistedOdooIds): void
    {
        if ($persistedOdooIds === []) {
            return;
        }

        $products = Product::withTrashed()
            ->whereIn('odoo_id', $persistedOdooIds)
            ->get(['id', 'odoo_id', 'codice', 'categoria_id', 'disponibile', 'deleted_at']);

        foreach ($products as $product) {
            $entry = [
                'id' => (int) $product->id,
                'odoo_id' => $product->odoo_id !== null ? (int) $product->odoo_id : null,
                'codice' => (string) $product->codice,
                'categoria_id' => $product->categoria_id !== null ? (int) $product->categoria_id : null,
                'disponibile' => (bool) $product->disponibile,
                'deleted_at' => $product->deleted_at?->format('Y-m-d H:i:s'),
            ];

            if ($entry['odoo_id'] !== null) {
                $productIndex['by_odoo_id'][$entry['odoo_id']] = $entry;
            }

            $productIndex['by_code'][$entry['codice']] = $entry;
        }
    }

    /**
     * @param  array{
     *     imported:int,
     *     updated:int,
     *     processed:int,
     *     errors:int,
     *     invalid_categories:int,
     *     skipped:int,
     *     chunks:int
     * }  $stats
     * @param  array{
     *     update_rows:array<int, array<string, mixed>>,
     *     insert_rows:array<int, array<string, mixed>>,
     *     persisted_odoo_ids:array<int, int>,
     *     imported:int,
     *     updated:int,
     *     processed:int,
     *     errors:int,
     *     invalid_categories:int,
     *     skipped:int
     * }  $chunkPayload
     */
    private function mergeStats(array &$stats, array $chunkPayload): void
    {
        $stats['imported'] += $chunkPayload['imported'];
        $stats['updated'] += $chunkPayload['updated'];
        $stats['processed'] += $chunkPayload['processed'];
        $stats['errors'] += $chunkPayload['errors'];
        $stats['invalid_categories'] += $chunkPayload['invalid_categories'];
        $stats['skipped'] += $chunkPayload['skipped'];
    }

    private function normalizePositiveInt(mixed $value): ?int
    {
        if (!is_int($value) && !is_numeric($value)) {
            return null;
        }

        $normalized = (int) $value;

        return $normalized > 0 ? $normalized : null;
    }

    private function normalizeWriteDate(mixed $value): ?string
    {
        if ($this->isEmptyValue($value)) {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $value)->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if ($value === null || $value === false) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private function normalizeOptionalBool(array $record, string $field): ?bool
    {
        if (!array_key_exists($field, $record)) {
            return null;
        }

        return (bool) $record[$field];
    }

    private function extractMany2OneId(mixed $value): ?int
    {
        if ($this->isEmptyMany2One($value)) {
            return null;
        }

        if (is_array($value) && isset($value[0]) && is_numeric($value[0])) {
            $normalized = (int) $value[0];

            return $normalized > 0 ? $normalized : null;
        }

        if (is_int($value) || is_numeric($value)) {
            $normalized = (int) $value;

            return $normalized > 0 ? $normalized : null;
        }

        return null;
    }

    private function extractMany2OneLabel(mixed $value): ?string
    {
        if (is_array($value) && array_key_exists(1, $value)) {
            return $this->normalizeNullableString($value[1]);
        }

        if (is_string($value)) {
            return $this->normalizeNullableString($value);
        }

        return null;
    }

    private function isEmptyMany2One(mixed $value): bool
    {
        return $value === null || $value === false || $value === '' || $value === [];
    }

    private function isEmptyValue(mixed $value): bool
    {
        return $value === null || $value === '';
    }

    /**
     * @return array{
     *     by_odoo_id:array<int, array{id:int, odoo_id:?int, codice:string, categoria_id:?int, disponibile:bool, deleted_at:?string}>,
     *     by_code:array<string, array{id:int, odoo_id:?int, codice:string, categoria_id:?int, disponibile:bool, deleted_at:?string}>
     * }
     */
    private function loadProductIndex(): array
    {
        $byOdooId = [];
        $byCode = [];

        $products = Product::withTrashed()
            ->get(['id', 'odoo_id', 'codice', 'categoria_id', 'disponibile', 'deleted_at']);

        foreach ($products as $product) {
            $entry = [
                'id' => (int) $product->id,
                'odoo_id' => $product->odoo_id !== null ? (int) $product->odoo_id : null,
                'codice' => (string) $product->codice,
                'categoria_id' => $product->categoria_id !== null ? (int) $product->categoria_id : null,
                'disponibile' => (bool) $product->disponibile,
                'deleted_at' => $product->deleted_at?->format('Y-m-d H:i:s'),
            ];

            if ($entry['odoo_id'] !== null) {
                $byOdooId[$entry['odoo_id']] = $entry;
            }

            $byCode[$entry['codice']] = $entry;
        }

        return [
            'by_odoo_id' => $byOdooId,
            'by_code' => $byCode,
        ];
    }

    /**
     * @return array<int, int>
     */
    private function loadCategoryIdsByOdooId(): array
    {
        return Categoria::query()
            ->whereNotNull('odoo_id')
            ->pluck('id', 'odoo_id')
            ->mapWithKeys(static fn (mixed $id, mixed $odooId): array => [(int) $odooId => (int) $id])
            ->all();
    }

    /**
     * @return array{
     *     imported:int,
     *     updated:int,
     *     processed:int,
     *     errors:int,
     *     invalid_categories:int,
     *     skipped:int,
     *     chunks:int
     * }
     */
    private function initialStats(): array
    {
        return [
            'imported' => 0,
            'updated' => 0,
            'processed' => 0,
            'errors' => 0,
            'invalid_categories' => 0,
            'skipped' => 0,
            'chunks' => 0,
        ];
    }
}
