<?php

declare(strict_types=1);

namespace App\Services\Odoo;

use App\Models\Categoria;
use App\Models\Listino;
use App\Models\ListinoItem;
use App\Models\Product;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class PricelistImportService
{
    private const PRICELIST_MODEL = 'product.pricelist';
    private const PRICELIST_ITEM_MODEL = 'product.pricelist.item';
    private const DEFAULT_CHUNK_SIZE = 200;
    private const PRICELIST_FIELDS = [
        'id',
        'name',
        'active',
        'sequence',
        'currency_id',
        'company_id',
        'write_date',
    ];
    private const PRICELIST_ITEM_FIELDS = [
        'id',
        'pricelist_id',
        'write_date',
        'name',
        'price',
        'date_start',
        'date_end',
        'min_quantity',
        'applied_on',
        'display_applied_on',
        'categ_id',
        'product_tmpl_id',
        'product_id',
        'base',
        'base_pricelist_id',
        'compute_price',
        'fixed_price',
        'percent_price',
        'price_discount',
        'price_round',
        'price_surcharge',
        'price_markup',
        'price_min_margin',
        'price_max_margin',
    ];

    private readonly OdooClient $client;

    public function __construct(?OdooClient $client = null)
    {
        $this->client = $client ?? OdooClient::fromConfig();
    }

    /**
     * @return array{
     *     pricelists_imported:int,
     *     pricelists_updated:int,
     *     pricelists_processed:int,
     *     pricelist_items_imported:int,
     *     pricelist_items_updated:int,
     *     pricelist_items_processed:int,
     *     invalid_references:int,
     *     errors:int,
     *     pricelist_chunks:int,
     *     pricelist_item_chunks:int
     * }
     */
    public function import(?CarbonInterface $since = null, int $chunkSize = self::DEFAULT_CHUNK_SIZE): array
    {
        $chunkSize = max(1, $chunkSize);
        $stats = $this->initialStats();

        Log::info('Odoo pricelist import started', [
            'chunk_size' => $chunkSize,
            'since' => $since?->format('Y-m-d H:i:s'),
        ]);

        try {
            $pricelistStats = $this->importPricelists($since, $chunkSize);
            $this->mergePricelistStats($stats, $pricelistStats);

            $itemStats = $this->importPricelistItems($since, $chunkSize);
            $this->mergeItemStats($stats, $itemStats);

            Log::info('Odoo pricelist import completed', $stats + [
                'chunk_size' => $chunkSize,
                'since' => $since?->format('Y-m-d H:i:s'),
            ]);

            return $stats;
        } catch (Throwable $exception) {
            Log::error('Odoo pricelist import failed', [
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
     *     chunks:int
     * }
     */
    private function importPricelists(?CarbonInterface $since, int $chunkSize): array
    {
        $stats = [
            'imported' => 0,
            'updated' => 0,
            'processed' => 0,
            'errors' => 0,
            'chunks' => 0,
        ];
        $offset = 0;
        $knownOdooIds = $this->loadKnownPricelistOdooIds();

        while (true) {
            $records = $this->client->searchRead(
                self::PRICELIST_MODEL,
                $this->buildDomain($since),
                self::PRICELIST_FIELDS,
                [
                    'limit' => $chunkSize,
                    'offset' => $offset,
                    'order' => 'write_date asc, id asc',
                ]
            );

            $count = count($records);

            if ($count === 0) {
                break;
            }

            $stats['chunks']++;

            Log::info('Odoo pricelist chunk fetched', [
                'offset' => $offset,
                'records' => $count,
            ]);

            $payload = $this->buildPricelistChunkPayload($records, $offset, $knownOdooIds);
            $stats['imported'] += $payload['imported'];
            $stats['updated'] += $payload['updated'];
            $stats['processed'] += count($payload['rows']);
            $stats['errors'] += $payload['errors'];

            $this->persistPricelists($payload['rows'], $offset);

            Log::info('Odoo pricelist chunk persisted', [
                'offset' => $offset,
                'processed' => count($payload['rows']),
                'imported' => $payload['imported'],
                'updated' => $payload['updated'],
                'errors' => $payload['errors'],
            ]);

            $offset += $count;

            if ($count < $chunkSize) {
                break;
            }
        }

        return $stats;
    }

    /**
     * @param  array<int, bool>  $knownOdooIds
     * @param  array<int, array<string, mixed>>  $records
     * @return array{
     *     rows:array<int, array<string, mixed>>,
     *     imported:int,
     *     updated:int,
     *     errors:int
     * }
     */
    private function buildPricelistChunkPayload(array $records, int $offset, array &$knownOdooIds): array
    {
        $rowsByOdooId = [];
        $imported = 0;
        $updated = 0;
        $errors = 0;

        foreach ($records as $record) {
            $normalized = $this->normalizePricelistRecord($record, $offset);

            if ($normalized === null) {
                $errors++;
                continue;
            }

            $odooId = $normalized['odoo_id'];

            if (isset($rowsByOdooId[$odooId])) {
                $errors++;

                Log::warning('Duplicate Odoo pricelist found in the same chunk', [
                    'offset' => $offset,
                    'odoo_id' => $odooId,
                ]);
            }

            $rowsByOdooId[$odooId] = $normalized;

            if (isset($knownOdooIds[$odooId])) {
                $updated++;
            } else {
                $knownOdooIds[$odooId] = true;
                $imported++;
            }
        }

        return [
            'rows' => array_values($rowsByOdooId),
            'imported' => $imported,
            'updated' => $updated,
            'errors' => $errors,
        ];
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>|null
     */
    private function normalizePricelistRecord(array $record, int $offset): ?array
    {
        $odooId = $this->normalizePositiveInt($record['id'] ?? null);
        $name = trim((string) ($record['name'] ?? ''));

        if ($odooId === null || $name === '') {
            Log::error('Odoo pricelist skipped because required fields are invalid', [
                'offset' => $offset,
                'record' => $record,
            ]);

            return null;
        }

        return [
            'odoo_id' => $odooId,
            'nome_listino' => $name,
            'tipo' => 'vendita',
            'valido_dal' => now()->toDateString(),
            'odoo_write_date' => $this->normalizeDateTime($record['write_date'] ?? null),
            'attivo' => $this->normalizeBool($record['active'] ?? true),
            'sequenza' => $this->normalizeInteger($record['sequence'] ?? null),
            'odoo_currency_id' => $this->extractMany2OneId($record['currency_id'] ?? null),
            'odoo_currency_name' => $this->extractMany2OneLabel($record['currency_id'] ?? null),
            'odoo_company_id' => $this->extractMany2OneId($record['company_id'] ?? null),
            'odoo_company_name' => $this->extractMany2OneLabel($record['company_id'] ?? null),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function persistPricelists(array $rows, int $offset): void
    {
        if ($rows === []) {
            return;
        }

        try {
            DB::transaction(static function () use ($rows): void {
                Listino::query()->upsert(
                    $rows,
                    ['odoo_id'],
                    [
                        'nome_listino',
                        'odoo_write_date',
                        'attivo',
                        'sequenza',
                        'odoo_currency_id',
                        'odoo_currency_name',
                        'odoo_company_id',
                        'odoo_company_name',
                        'updated_at',
                    ]
                );
            });
        } catch (Throwable $exception) {
            Log::error('Odoo pricelist upsert failed', [
                'offset' => $offset,
                'rows' => count($rows),
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
     *     invalid_references:int,
     *     errors:int,
     *     chunks:int
     * }
     */
    private function importPricelistItems(?CarbonInterface $since, int $chunkSize): array
    {
        $stats = [
            'imported' => 0,
            'updated' => 0,
            'processed' => 0,
            'invalid_references' => 0,
            'errors' => 0,
            'chunks' => 0,
        ];
        $offset = 0;
        $knownOdooIds = $this->loadKnownPricelistItemOdooIds();
        $listinoIdsByOdooId = $this->loadListinoIdsByOdooId();
        $categoriaIdsByOdooId = $this->loadCategoriaIdsByOdooId();
        $productIdsByOdooId = $this->loadProductIdsByOdooId();

        while (true) {
            $records = $this->client->searchRead(
                self::PRICELIST_ITEM_MODEL,
                $this->buildDomain($since),
                self::PRICELIST_ITEM_FIELDS,
                [
                    'limit' => $chunkSize,
                    'offset' => $offset,
                    'order' => 'write_date asc, id asc',
                ]
            );

            $count = count($records);

            if ($count === 0) {
                break;
            }

            $stats['chunks']++;

            Log::info('Odoo pricelist item chunk fetched', [
                'offset' => $offset,
                'records' => $count,
            ]);

            $payload = $this->buildPricelistItemChunkPayload(
                $records,
                $offset,
                $knownOdooIds,
                $listinoIdsByOdooId,
                $categoriaIdsByOdooId,
                $productIdsByOdooId
            );

            $stats['imported'] += $payload['imported'];
            $stats['updated'] += $payload['updated'];
            $stats['processed'] += count($payload['rows']);
            $stats['invalid_references'] += $payload['invalid_references'];
            $stats['errors'] += $payload['errors'];

            $this->persistPricelistItems($payload['rows'], $offset);

            Log::info('Odoo pricelist item chunk persisted', [
                'offset' => $offset,
                'processed' => count($payload['rows']),
                'imported' => $payload['imported'],
                'updated' => $payload['updated'],
                'invalid_references' => $payload['invalid_references'],
                'errors' => $payload['errors'],
            ]);

            $offset += $count;

            if ($count < $chunkSize) {
                break;
            }
        }

        return $stats;
    }

    /**
     * @param  array<int, bool>  $knownOdooIds
     * @param  array<int, int>  $listinoIdsByOdooId
     * @param  array<int, int>  $categoriaIdsByOdooId
     * @param  array<int, int>  $productIdsByOdooId
     * @param  array<int, array<string, mixed>>  $records
     * @return array{
     *     rows:array<int, array<string, mixed>>,
     *     imported:int,
     *     updated:int,
     *     invalid_references:int,
     *     errors:int
     * }
     */
    private function buildPricelistItemChunkPayload(
        array $records,
        int $offset,
        array &$knownOdooIds,
        array $listinoIdsByOdooId,
        array $categoriaIdsByOdooId,
        array $productIdsByOdooId
    ): array {
        $rowsByOdooId = [];
        $imported = 0;
        $updated = 0;
        $invalidReferences = 0;
        $errors = 0;

        foreach ($records as $record) {
            $normalized = $this->normalizePricelistItemRecord(
                $record,
                $offset,
                $listinoIdsByOdooId,
                $categoriaIdsByOdooId,
                $productIdsByOdooId
            );

            $errors += $normalized['errors'];
            $invalidReferences += $normalized['invalid_references'];

            $row = $normalized['row'];

            if ($row === null) {
                continue;
            }

            $odooId = (int) $row['odoo_id'];

            if (isset($rowsByOdooId[$odooId])) {
                $errors++;

                Log::warning('Duplicate Odoo pricelist item found in the same chunk', [
                    'offset' => $offset,
                    'odoo_id' => $odooId,
                ]);
            }

            $rowsByOdooId[$odooId] = $row;

            if (isset($knownOdooIds[$odooId])) {
                $updated++;
            } else {
                $knownOdooIds[$odooId] = true;
                $imported++;
            }
        }

        return [
            'rows' => array_values($rowsByOdooId),
            'imported' => $imported,
            'updated' => $updated,
            'invalid_references' => $invalidReferences,
            'errors' => $errors,
        ];
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<int, int>  $listinoIdsByOdooId
     * @param  array<int, int>  $categoriaIdsByOdooId
     * @param  array<int, int>  $productIdsByOdooId
     * @return array{
     *     row:?array<string, mixed>,
     *     invalid_references:int,
     *     errors:int
     * }
     */
    private function normalizePricelistItemRecord(
        array $record,
        int $offset,
        array $listinoIdsByOdooId,
        array $categoriaIdsByOdooId,
        array $productIdsByOdooId
    ): array {
        $odooId = $this->normalizePositiveInt($record['id'] ?? null);
        $appliedOn = $this->normalizeNullableString($record['applied_on'] ?? null);

        if ($odooId === null || $appliedOn === null) {
            Log::error('Odoo pricelist item skipped because required fields are invalid', [
                'offset' => $offset,
                'record' => $record,
            ]);

            return [
                'row' => null,
                'invalid_references' => 0,
                'errors' => 1,
            ];
        }

        $invalidReferences = 0;

        $odooPricelistId = $this->extractMany2OneId($record['pricelist_id'] ?? null);
        $listinoId = $odooPricelistId !== null ? ($listinoIdsByOdooId[$odooPricelistId] ?? null) : null;

        if ($odooPricelistId !== null && $listinoId === null) {
            $invalidReferences++;

            Log::warning('Odoo pricelist item refers to a missing local pricelist', [
                'offset' => $offset,
                'odoo_item_id' => $odooId,
                'odoo_pricelist_id' => $odooPricelistId,
            ]);
        }

        $odooCategoriaId = $this->extractMany2OneId($record['categ_id'] ?? null);
        $categoriaId = $odooCategoriaId !== null ? ($categoriaIdsByOdooId[$odooCategoriaId] ?? null) : null;

        if ($odooCategoriaId !== null && $categoriaId === null) {
            $invalidReferences++;

            Log::warning('Odoo pricelist item refers to a missing local category', [
                'offset' => $offset,
                'odoo_item_id' => $odooId,
                'odoo_categoria_id' => $odooCategoriaId,
            ]);
        }

        $odooProductTmplId = $this->extractMany2OneId($record['product_tmpl_id'] ?? null);
        $productId = $odooProductTmplId !== null ? ($productIdsByOdooId[$odooProductTmplId] ?? null) : null;

        if ($odooProductTmplId !== null && $productId === null) {
            $invalidReferences++;

            Log::warning('Odoo pricelist item refers to a missing local product template', [
                'offset' => $offset,
                'odoo_item_id' => $odooId,
                'odoo_product_tmpl_id' => $odooProductTmplId,
            ]);
        }

        $odooBasePricelistId = $this->extractMany2OneId($record['base_pricelist_id'] ?? null);
        $baseListinoId = $odooBasePricelistId !== null ? ($listinoIdsByOdooId[$odooBasePricelistId] ?? null) : null;

        if ($odooBasePricelistId !== null && $baseListinoId === null) {
            $invalidReferences++;

            Log::warning('Odoo pricelist item refers to a missing local base pricelist', [
                'offset' => $offset,
                'odoo_item_id' => $odooId,
                'odoo_base_pricelist_id' => $odooBasePricelistId,
            ]);
        }

        return [
            'row' => [
                'odoo_id' => $odooId,
                'listino_id' => $listinoId,
                'odoo_pricelist_id' => $odooPricelistId,
                'odoo_write_date' => $this->normalizeDateTime($record['write_date'] ?? null),
                'nome_regola' => $this->normalizeNullableString($record['name'] ?? null),
                'descrizione_prezzo' => $this->normalizeNullableString($record['price'] ?? null),
                'applied_on' => $appliedOn,
                'display_applied_on' => $this->normalizeNullableString($record['display_applied_on'] ?? null),
                'min_quantity' => $this->normalizeDecimal($record['min_quantity'] ?? null),
                'date_start' => $this->normalizeDateTime($record['date_start'] ?? null),
                'date_end' => $this->normalizeDateTime($record['date_end'] ?? null),
                'categoria_id' => $categoriaId,
                'odoo_categoria_id' => $odooCategoriaId,
                'product_id' => $productId,
                'odoo_product_tmpl_id' => $odooProductTmplId,
                'odoo_product_variant_id' => $this->extractMany2OneId($record['product_id'] ?? null),
                'base' => $this->normalizeNullableString($record['base'] ?? null),
                'base_pricelist_id' => $baseListinoId,
                'odoo_base_pricelist_id' => $odooBasePricelistId,
                'compute_price' => $this->normalizeNullableString($record['compute_price'] ?? null),
                'fixed_price' => $this->normalizeDecimal($record['fixed_price'] ?? null),
                'percent_price' => $this->normalizeDecimal($record['percent_price'] ?? null),
                'price_discount' => $this->normalizeDecimal($record['price_discount'] ?? null),
                'price_round' => $this->normalizeDecimal($record['price_round'] ?? null),
                'price_surcharge' => $this->normalizeDecimal($record['price_surcharge'] ?? null),
                'price_markup' => $this->normalizeDecimal($record['price_markup'] ?? null),
                'price_min_margin' => $this->normalizeDecimal($record['price_min_margin'] ?? null),
                'price_max_margin' => $this->normalizeDecimal($record['price_max_margin'] ?? null),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            'invalid_references' => $invalidReferences,
            'errors' => 0,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function persistPricelistItems(array $rows, int $offset): void
    {
        if ($rows === []) {
            return;
        }

        try {
            DB::transaction(static function () use ($rows): void {
                ListinoItem::query()->upsert(
                    $rows,
                    ['odoo_id'],
                    [
                        'listino_id',
                        'odoo_pricelist_id',
                        'odoo_write_date',
                        'nome_regola',
                        'descrizione_prezzo',
                        'applied_on',
                        'display_applied_on',
                        'min_quantity',
                        'date_start',
                        'date_end',
                        'categoria_id',
                        'odoo_categoria_id',
                        'product_id',
                        'odoo_product_tmpl_id',
                        'odoo_product_variant_id',
                        'base',
                        'base_pricelist_id',
                        'odoo_base_pricelist_id',
                        'compute_price',
                        'fixed_price',
                        'percent_price',
                        'price_discount',
                        'price_round',
                        'price_surcharge',
                        'price_markup',
                        'price_min_margin',
                        'price_max_margin',
                        'updated_at',
                    ]
                );
            });
        } catch (Throwable $exception) {
            Log::error('Odoo pricelist item upsert failed', [
                'offset' => $offset,
                'rows' => count($rows),
                'exception' => $exception,
            ]);

            throw $exception;
        }
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
     * @return array<int, bool>
     */
    private function loadKnownPricelistOdooIds(): array
    {
        return $this->toKnownSet(
            Listino::query()
                ->whereNotNull('odoo_id')
                ->pluck('odoo_id')
                ->all()
        );
    }

    /**
     * @return array<int, bool>
     */
    private function loadKnownPricelistItemOdooIds(): array
    {
        return $this->toKnownSet(
            ListinoItem::query()
                ->whereNotNull('odoo_id')
                ->pluck('odoo_id')
                ->all()
        );
    }

    /**
     * @return array<int, int>
     */
    private function loadListinoIdsByOdooId(): array
    {
        return Listino::query()
            ->whereNotNull('odoo_id')
            ->pluck('id', 'odoo_id')
            ->mapWithKeys(static fn (mixed $id, mixed $odooId): array => [(int) $odooId => (int) $id])
            ->all();
    }

    /**
     * @return array<int, int>
     */
    private function loadCategoriaIdsByOdooId(): array
    {
        return Categoria::query()
            ->whereNotNull('odoo_id')
            ->pluck('id', 'odoo_id')
            ->mapWithKeys(static fn (mixed $id, mixed $odooId): array => [(int) $odooId => (int) $id])
            ->all();
    }

    /**
     * @return array<int, int>
     */
    private function loadProductIdsByOdooId(): array
    {
        return Product::query()
            ->whereNotNull('odoo_id')
            ->pluck('id', 'odoo_id')
            ->mapWithKeys(static fn (mixed $id, mixed $odooId): array => [(int) $odooId => (int) $id])
            ->all();
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<int, bool>
     */
    private function toKnownSet(array $values): array
    {
        $set = [];

        foreach ($values as $value) {
            if ($value === null) {
                continue;
            }

            $set[(int) $value] = true;
        }

        return $set;
    }

    /**
     * @param  array{
     *     pricelists_imported:int,
     *     pricelists_updated:int,
     *     pricelists_processed:int,
     *     pricelist_items_imported:int,
     *     pricelist_items_updated:int,
     *     pricelist_items_processed:int,
     *     invalid_references:int,
     *     errors:int,
     *     pricelist_chunks:int,
     *     pricelist_item_chunks:int
     * }  $stats
     * @param  array{
     *     imported:int,
     *     updated:int,
     *     processed:int,
     *     errors:int,
     *     chunks:int
     * }  $pricelistStats
     */
    private function mergePricelistStats(array &$stats, array $pricelistStats): void
    {
        $stats['pricelists_imported'] += $pricelistStats['imported'];
        $stats['pricelists_updated'] += $pricelistStats['updated'];
        $stats['pricelists_processed'] += $pricelistStats['processed'];
        $stats['errors'] += $pricelistStats['errors'];
        $stats['pricelist_chunks'] += $pricelistStats['chunks'];
    }

    /**
     * @param  array{
     *     pricelists_imported:int,
     *     pricelists_updated:int,
     *     pricelists_processed:int,
     *     pricelist_items_imported:int,
     *     pricelist_items_updated:int,
     *     pricelist_items_processed:int,
     *     invalid_references:int,
     *     errors:int,
     *     pricelist_chunks:int,
     *     pricelist_item_chunks:int
     * }  $stats
     * @param  array{
     *     imported:int,
     *     updated:int,
     *     processed:int,
     *     invalid_references:int,
     *     errors:int,
     *     chunks:int
     * }  $itemStats
     */
    private function mergeItemStats(array &$stats, array $itemStats): void
    {
        $stats['pricelist_items_imported'] += $itemStats['imported'];
        $stats['pricelist_items_updated'] += $itemStats['updated'];
        $stats['pricelist_items_processed'] += $itemStats['processed'];
        $stats['invalid_references'] += $itemStats['invalid_references'];
        $stats['errors'] += $itemStats['errors'];
        $stats['pricelist_item_chunks'] += $itemStats['chunks'];
    }

    private function normalizePositiveInt(mixed $value): ?int
    {
        if (!is_int($value) && !is_numeric($value)) {
            return null;
        }

        $normalized = (int) $value;

        return $normalized > 0 ? $normalized : null;
    }

    private function normalizeInteger(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_int($value) && !is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    private function normalizeDecimal(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }

        if (!is_numeric($value)) {
            return null;
        }

        return number_format((float) $value, 4, '.', '');
    }

    private function normalizeDateTime(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === false) {
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

    private function normalizeBool(mixed $value): bool
    {
        return (bool) $value;
    }

    private function extractMany2OneId(mixed $value): ?int
    {
        if ($value === null || $value === false || $value === '' || $value === []) {
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

    /**
     * @return array{
     *     pricelists_imported:int,
     *     pricelists_updated:int,
     *     pricelists_processed:int,
     *     pricelist_items_imported:int,
     *     pricelist_items_updated:int,
     *     pricelist_items_processed:int,
     *     invalid_references:int,
     *     errors:int,
     *     pricelist_chunks:int,
     *     pricelist_item_chunks:int
     * }
     */
    private function initialStats(): array
    {
        return [
            'pricelists_imported' => 0,
            'pricelists_updated' => 0,
            'pricelists_processed' => 0,
            'pricelist_items_imported' => 0,
            'pricelist_items_updated' => 0,
            'pricelist_items_processed' => 0,
            'invalid_references' => 0,
            'errors' => 0,
            'pricelist_chunks' => 0,
            'pricelist_item_chunks' => 0,
        ];
    }
}
