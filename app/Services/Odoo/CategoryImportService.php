<?php

declare(strict_types=1);

namespace App\Services\Odoo;

use App\Models\Categoria;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class CategoryImportService
{
    private const MODEL = 'product.category';
    private const DEFAULT_CHUNK_SIZE = 200;
    private const PARENT_BATCH_SIZE = 500;
    private const FIELDS = ['id', 'name', 'parent_id', 'write_date'];

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
     *     invalid_parents:int,
     *     chunks:int
     * }
     */
    public function import(?CarbonInterface $since = null, int $chunkSize = self::DEFAULT_CHUNK_SIZE): array
    {
        $chunkSize = max(1, $chunkSize);
        $stats = $this->initialStats();
        $knownOdooIds = $this->loadKnownOdooIds();
        $pendingParentLinks = [];
        $offset = 0;

        Log::info('Odoo category import started', [
            'chunk_size' => $chunkSize,
            'since' => $since?->format('Y-m-d H:i:s'),
        ]);

        try {
            while (true) {
                $records = $this->fetchChunk($offset, $chunkSize, $since);
                $recordCount = count($records);

                if ($recordCount === 0) {
                    break;
                }

                $stats['chunks']++;

                Log::info('Odoo category chunk fetched', [
                    'offset' => $offset,
                    'records' => $recordCount,
                ]);

                $normalizedChunk = $this->normalizeChunk($records, $offset);
                $categories = $normalizedChunk['categories'];
                $parentLinks = $normalizedChunk['parent_links'];
                $stats['errors'] += $normalizedChunk['errors'];

                if ($categories !== []) {
                    $chunkStats = $this->calculateChunkStats($categories, $knownOdooIds);
                    $this->upsertCategories($categories, $offset);

                    $stats['processed'] += count($categories);
                    $stats['imported'] += $chunkStats['imported'];
                    $stats['updated'] += $chunkStats['updated'];

                    Log::info('Odoo category chunk persisted', [
                        'offset' => $offset,
                        'processed' => count($categories),
                        'imported' => $chunkStats['imported'],
                        'updated' => $chunkStats['updated'],
                    ]);
                }

                if ($parentLinks !== []) {
                    array_push($pendingParentLinks, ...$parentLinks);
                }

                $offset += $recordCount;

                if ($recordCount < $chunkSize) {
                    break;
                }
            }

            $stats['invalid_parents'] = $this->syncParentAssignments($pendingParentLinks);
            $stats['errors'] += $stats['invalid_parents'];

            Log::info('Odoo category import completed', $stats + [
                'chunk_size' => $chunkSize,
                'since' => $since?->format('Y-m-d H:i:s'),
            ]);

            return $stats;
        } catch (Throwable $exception) {
            Log::error('Odoo category import failed', [
                'offset' => $offset,
                'chunk_size' => $chunkSize,
                'since' => $since?->format('Y-m-d H:i:s'),
                'exception' => $exception,
            ]);

            throw $exception;
        }
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
     * @return array{
     *     categories:array<int, array{odoo_id:int,name:string,write_date:?string}>,
     *     parent_links:array<int, array{child_odoo_id:int,parent_odoo_id:int,name:string,offset:int}>,
     *     errors:int
     * }
     */
    private function normalizeChunk(array $records, int $offset): array
    {
        $categoriesByOdooId = [];
        $parentLinksByChildId = [];
        $errors = 0;

        foreach ($records as $record) {
            $normalized = $this->normalizeRecord($record, $offset);
            $errors += $normalized['errors'];

            $category = $normalized['category'];
            if ($category === null) {
                continue;
            }

            $odooId = $category['odoo_id'];

            if (isset($categoriesByOdooId[$odooId])) {
                $errors++;

                Log::warning('Duplicate Odoo category found in the same chunk', [
                    'offset' => $offset,
                    'odoo_id' => $odooId,
                ]);
            }

            $categoriesByOdooId[$odooId] = $category;

            $parentLink = $normalized['parent_link'];
            if ($parentLink === null) {
                unset($parentLinksByChildId[$odooId]);
                continue;
            }

            $parentLinksByChildId[$odooId] = $parentLink;
        }

        return [
            'categories' => array_values($categoriesByOdooId),
            'parent_links' => array_values($parentLinksByChildId),
            'errors' => $errors,
        ];
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array{
     *     category:?array{odoo_id:int,name:string,write_date:?string},
     *     parent_link:?array{child_odoo_id:int,parent_odoo_id:int,name:string,offset:int},
     *     errors:int
     * }
     */
    private function normalizeRecord(array $record, int $offset): array
    {
        $errors = 0;
        $odooId = $this->normalizeOdooId($record['id'] ?? null);

        if ($odooId === null) {
            Log::error('Odoo category skipped because id is invalid', [
                'offset' => $offset,
                'record' => $record,
            ]);

            return [
                'category' => null,
                'parent_link' => null,
                'errors' => 1,
            ];
        }

        $name = trim((string) ($record['name'] ?? ''));
        if ($name === '') {
            Log::error('Odoo category skipped because name is missing', [
                'offset' => $offset,
                'odoo_id' => $odooId,
            ]);

            return [
                'category' => null,
                'parent_link' => null,
                'errors' => 1,
            ];
        }

        $rawWriteDate = $record['write_date'] ?? null;
        $writeDate = $this->normalizeWriteDate($rawWriteDate);

        if (!$this->isEmptyValue($rawWriteDate) && $writeDate === null) {
            $errors++;

            Log::warning('Odoo category has an invalid write_date', [
                'offset' => $offset,
                'odoo_id' => $odooId,
                'write_date' => $rawWriteDate,
            ]);
        }

        $rawParent = $record['parent_id'] ?? null;
        $parentOdooId = $this->extractParentOdooId($rawParent);

        if (!$this->isEmptyParentValue($rawParent) && $parentOdooId === null) {
            $errors++;

            Log::warning('Odoo category has an invalid parent payload', [
                'offset' => $offset,
                'odoo_id' => $odooId,
                'parent_id' => $rawParent,
            ]);
        }

        if ($parentOdooId === $odooId) {
            $errors++;
            $parentOdooId = null;

            Log::warning('Odoo category parent points to itself', [
                'offset' => $offset,
                'odoo_id' => $odooId,
            ]);
        }

        return [
            'category' => [
                'odoo_id' => $odooId,
                'name' => $name,
                'write_date' => $writeDate,
            ],
            'parent_link' => $parentOdooId === null ? null : [
                'child_odoo_id' => $odooId,
                'parent_odoo_id' => $parentOdooId,
                'name' => $name,
                'offset' => $offset,
            ],
            'errors' => $errors,
        ];
    }

    private function normalizeOdooId(mixed $value): ?int
    {
        if (!is_int($value) && !is_numeric($value)) {
            return null;
        }

        $odooId = (int) $value;

        return $odooId > 0 ? $odooId : null;
    }

    private function extractParentOdooId(mixed $value): ?int
    {
        if ($this->isEmptyParentValue($value)) {
            return null;
        }

        if (is_array($value) && isset($value[0]) && is_numeric($value[0])) {
            $parentOdooId = (int) $value[0];

            return $parentOdooId > 0 ? $parentOdooId : null;
        }

        if (is_int($value) || is_numeric($value)) {
            $parentOdooId = (int) $value;

            return $parentOdooId > 0 ? $parentOdooId : null;
        }

        return null;
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

    private function isEmptyParentValue(mixed $value): bool
    {
        return $value === null || $value === false || $value === '' || $value === [];
    }

    private function isEmptyValue(mixed $value): bool
    {
        return $value === null || $value === '';
    }

    /**
     * @param  array<int, array{odoo_id:int,name:string,write_date:?string}>  $categories
     * @param  array<int, bool>  $knownOdooIds
     * @return array{imported:int,updated:int}
     */
    private function calculateChunkStats(array $categories, array &$knownOdooIds): array
    {
        $imported = 0;
        $updated = 0;

        foreach ($categories as $category) {
            $odooId = $category['odoo_id'];

            if (isset($knownOdooIds[$odooId])) {
                $updated++;
                continue;
            }

            $knownOdooIds[$odooId] = true;
            $imported++;
        }

        return [
            'imported' => $imported,
            'updated' => $updated,
        ];
    }

    /**
     * @param  array<int, array{odoo_id:int,name:string,write_date:?string}>  $categories
     */
    private function upsertCategories(array $categories, int $offset): void
    {
        $timestamp = now();
        $rows = [];

        foreach ($categories as $category) {
            $rows[] = [
                'odoo_id' => $category['odoo_id'],
                'nome' => $category['name'],
                'categoria_padre_id' => null,
                'odoo_write_date' => $category['write_date'],
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }

        try {
            DB::transaction(static function () use ($rows): void {
                Categoria::query()->upsert(
                    $rows,
                    ['odoo_id'],
                    ['nome', 'categoria_padre_id', 'odoo_write_date', 'updated_at']
                );
            });
        } catch (Throwable $exception) {
            Log::error('Odoo category upsert failed', [
                'offset' => $offset,
                'rows' => count($rows),
                'exception' => $exception,
            ]);

            throw $exception;
        }
    }

    /**
     * @param  array<int, array{child_odoo_id:int,parent_odoo_id:int,name:string,offset:int}>  $pendingParentLinks
     */
    private function syncParentAssignments(array $pendingParentLinks): int
    {
        if ($pendingParentLinks === []) {
            return 0;
        }

        $localIdsByOdooId = $this->loadLocalIdsByOdooId();
        $invalidParents = 0;

        foreach (array_chunk($pendingParentLinks, self::PARENT_BATCH_SIZE) as $batchIndex => $batch) {
            $timestamp = now();
            $rowsByLocalId = [];

            foreach ($batch as $link) {
                $childLocalId = $localIdsByOdooId[$link['child_odoo_id']] ?? null;
                $parentLocalId = $localIdsByOdooId[$link['parent_odoo_id']] ?? null;

                if ($childLocalId === null || $parentLocalId === null) {
                    $invalidParents++;

                    Log::warning('Odoo category parent could not be matched locally', [
                        'offset' => $link['offset'],
                        'child_odoo_id' => $link['child_odoo_id'],
                        'parent_odoo_id' => $link['parent_odoo_id'],
                        'name' => $link['name'],
                    ]);

                    continue;
                }

                if ($childLocalId === $parentLocalId) {
                    $invalidParents++;

                    Log::warning('Odoo category parent resolves to the same local category', [
                        'offset' => $link['offset'],
                        'child_odoo_id' => $link['child_odoo_id'],
                        'parent_odoo_id' => $link['parent_odoo_id'],
                        'name' => $link['name'],
                    ]);

                    continue;
                }

                $rowsByLocalId[$childLocalId] = [
                    'id' => $childLocalId,
                    'categoria_padre_id' => $parentLocalId,
                    'updated_at' => $timestamp,
                ];
            }

            if ($rowsByLocalId === []) {
                continue;
            }

            try {
                DB::transaction(static function () use ($rowsByLocalId): void {
                    Categoria::query()->upsert(
                        array_values($rowsByLocalId),
                        ['id'],
                        ['categoria_padre_id', 'updated_at']
                    );
                });
            } catch (Throwable $exception) {
                Log::error('Odoo category parent sync failed', [
                    'batch' => $batchIndex + 1,
                    'rows' => count($rowsByLocalId),
                    'exception' => $exception,
                ]);

                throw $exception;
            }

            Log::info('Odoo category parent batch synced', [
                'batch' => $batchIndex + 1,
                'processed' => count($batch),
                'updated' => count($rowsByLocalId),
            ]);
        }

        return $invalidParents;
    }

    /**
     * @return array<int, bool>
     */
    private function loadKnownOdooIds(): array
    {
        $localIdsByOdooId = $this->loadLocalIdsByOdooId();
        $knownOdooIds = [];

        foreach (array_keys($localIdsByOdooId) as $odooId) {
            $knownOdooIds[(int) $odooId] = true;
        }

        return $knownOdooIds;
    }

    /**
     * @return array<int, int>
     */
    private function loadLocalIdsByOdooId(): array
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
     *     invalid_parents:int,
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
            'invalid_parents' => 0,
            'chunks' => 0,
        ];
    }
}
