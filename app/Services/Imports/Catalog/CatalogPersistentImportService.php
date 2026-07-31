<?php

declare(strict_types=1);

namespace App\Services\Imports\Catalog;

use App\Models\Fornitore;
use App\Models\ImportBatch;
use App\Models\Listino;
use App\Models\ListinoReferenza;
use App\Models\ReferenzaFornitore;
use App\Services\Imports\Catalog\Data\CatalogImportImage;
use App\Services\Imports\Catalog\Data\CatalogInspectionResult;
use App\Services\Imports\Catalog\Exceptions\CatalogImportConflictException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final readonly class CatalogPersistentImportService
{
    public function __construct(private CatalogImportInspector $inspector) {}

    public function import(
        string $supplierCode,
        string $listName,
        string $filePath,
        ?string $profile = null,
        ?int $userId = null,
        ?string $originalFileName = null,
    ): ImportBatch {
        $supplierCode = strtoupper(trim($supplierCode));
        $listName = trim($listName);
        $profile = $supplierCode === 'ICA'
            ? strtolower(trim((string) $profile)) ?: null
            : null;
        $realPath = realpath($filePath);

        if ($listName === '') {
            throw new InvalidArgumentException('Il nome del listino e obbligatorio.');
        }

        if ($realPath === false || ! is_file($realPath)) {
            throw new InvalidArgumentException("File non trovato: {$filePath}");
        }

        $supplier = Fornitore::query()->where('code', $supplierCode)->first();
        if ($supplier === null) {
            throw new InvalidArgumentException(
                "Fornitore {$supplierCode} non configurato. Eseguire SupplierSeeder."
            );
        }

        $batch = ImportBatch::query()->create([
            'fornitore_id' => $supplier->getKey(),
            'user_id' => $userId,
            'nome_file_originale' => basename($originalFileName ?: $realPath),
            'file_hash' => hash_file('sha256', $realPath),
            'profilo' => $profile,
            'stato' => ImportBatch::STATUS_RUNNING,
            'iniziato_il' => now(),
        ]);

        $writtenImagePaths = [];

        try {
            $inspection = $this->inspector->inspect($supplierCode, $realPath, $profile);
            $result = DB::transaction(function () use (
                $batch,
                $inspection,
                $listName,
                $supplier,
                &$writtenImagePaths,
            ): array {
                [$validFrom, $validTo] = $this->validityDates($inspection);
                $listino = $this->findOrCreateListino(
                    $supplier,
                    $listName,
                    $validFrom,
                    $validTo
                );

                $blockingConflicts = $this->blockingConflicts($inspection);
                if ($blockingConflicts !== []) {
                    throw new CatalogImportConflictException($blockingConflicts);
                }

                $counts = [
                    'referenze_create' => 0,
                    'referenze_aggiornate' => 0,
                    'prezzi_creati' => 0,
                    'prezzi_aggiornati' => 0,
                    'righe_ignorate' => count($inspection->parsed->ignoredRows),
                ];
                $uniqueRows = [];

                foreach ($inspection->parsed->rows as $row) {
                    if (! $row->isValid() || $row->supplierCode === null) {
                        $counts['righe_ignorate']++;

                        continue;
                    }

                    if (isset($uniqueRows[$row->supplierCode])) {
                        $counts['righe_ignorate']++;

                        continue;
                    }

                    $uniqueRows[$row->supplierCode] = $row;
                }

                foreach ($uniqueRows as $row) {
                    $reference = ReferenzaFornitore::query()->firstOrNew([
                        'fornitore_id' => $supplier->getKey(),
                        'supplier_code' => $row->supplierCode,
                    ]);
                    $referenceWasNew = ! $reference->exists;
                    [$imagePath, $imageHash] = $this->storeImage(
                        (string) $supplier->code,
                        $row->image,
                        $writtenImagePaths
                    );
                    $reference->fill([
                        'customer_article_code' => $row->customerArticleCode,
                        'external_source_id' => $row->externalSourceId,
                        'descrizione' => $row->description,
                        'categoria' => $row->category,
                        'sales_unit' => $row->salesUnit,
                        'ordinabile' => $row->orderable,
                        'motivo_non_ordinabile' => $row->nonOrderableReason,
                        'source_profile' => $row->sourceProfile,
                        'source_hash' => $row->sourceHash,
                        'source_metadata' => [
                            'file_hash' => $inspection->parsed->fileHash,
                            'source_sheet' => $row->sourceSheet,
                            'source_row' => $row->sourceRow,
                        ],
                    ]);

                    if ($imagePath !== null) {
                        $reference->immagine_path = $imagePath;
                        $reference->immagine_hash = $imageHash;
                    }

                    $referenceChanged = $reference->isDirty();
                    $reference->save();

                    if ($referenceWasNew) {
                        $counts['referenze_create']++;
                    } elseif ($referenceChanged) {
                        $counts['referenze_aggiornate']++;
                    }

                    foreach ($row->packagings as $level => $packaging) {
                        $reference->packagings()->updateOrCreate(
                            [
                                'unita_contenitore' => $packaging->fromUnit,
                                'unita_contenuta' => $packaging->toUnit,
                            ],
                            [
                                'quantita' => $packaging->multiplier,
                                'livello' => $level + 1,
                                'origine_campo' => $packaging->sourceField,
                                'origine_valore' => is_scalar($packaging->sourceValue)
                                    ? (string) $packaging->sourceValue
                                    : null,
                                'obbligatorio' => $packaging->mandatory,
                            ]
                        );
                    }

                    $listPrice = ListinoReferenza::query()->firstOrNew([
                        'listino_id' => $listino->getKey(),
                        'referenza_fornitore_id' => $reference->getKey(),
                    ]);
                    $priceWasNew = ! $listPrice->exists;
                    $priceAttributes = [
                        'prezzo_sorgente' => $row->sourcePrice,
                        'price_unit' => $row->sourcePriceUnit,
                        'prezzo_lordo' => $row->grossPrice,
                        'sconto_percentuale' => $row->discountPercentage,
                        'iva_percentuale' => $row->vatPercentage,
                        'prezzo_cartone' => $row->cartonPrice,
                        'ordinabile' => $row->orderable,
                        'motivo_non_ordinabile' => $row->nonOrderableReason,
                    ];

                    if ($priceWasNew || ! $listPrice->modificato_manualmente) {
                        $priceAttributes['prezzo'] = $row->sourcePrice;
                    }

                    $listPrice->fill($priceAttributes);
                    $priceChanged = $listPrice->isDirty();
                    $listPrice->save();

                    if ($priceWasNew) {
                        $counts['prezzi_creati']++;
                    } elseif ($priceChanged) {
                        $counts['prezzi_aggiornati']++;
                    }
                }

                $batch->update([
                    'listino_id' => $listino->getKey(),
                    'stato' => ImportBatch::STATUS_COMPLETED,
                    'righe_lette' => $inspection->parsed->rowsRead,
                    ...$counts,
                    'warnings' => $this->warnings($inspection),
                    'errori' => [],
                    'riepilogo' => $this->summary($inspection),
                    'completato_il' => now(),
                ]);

                return [$batch, $listino];
            });

            /** @var ImportBatch $completedBatch */
            $completedBatch = $result[0];

            return $completedBatch->fresh(['fornitore', 'listino']);
        } catch (Throwable $exception) {
            foreach ($writtenImagePaths as $path) {
                Storage::disk('public')->delete($path);
            }

            $conflicts = $exception instanceof CatalogImportConflictException
                ? $exception->conflicts
                : [];

            $batch->refresh()->update([
                'stato' => ImportBatch::STATUS_FAILED,
                'righe_lette' => isset($inspection)
                    ? $inspection->parsed->rowsRead
                    : 0,
                'righe_ignorate' => isset($inspection)
                    ? count($inspection->parsed->ignoredRows)
                    : 0,
                'warnings' => isset($inspection)
                    ? $this->warnings($inspection)
                    : [],
                'errori' => [$exception->getMessage()],
                'riepilogo' => isset($inspection)
                    ? $this->summary($inspection, $conflicts)
                    : null,
                'completato_il' => now(),
            ]);

            throw $exception;
        }
    }

    private function findOrCreateListino(
        Fornitore $supplier,
        string $listName,
        ?string $validFrom,
        ?string $validTo,
    ): Listino {
        $listino = Listino::query()->firstOrNew([
            'fornitore_id' => $supplier->getKey(),
            'nome_listino' => $listName,
        ]);
        $attributes = [
            'tipo' => 'acquisto',
            'attivo' => true,
        ];

        if (! $listino->exists || $validFrom !== null) {
            $attributes['valido_dal'] = $validFrom;
            $attributes['valido_al'] = $validTo;
        }

        $listino->fill($attributes);

        if (! $listino->exists) {
            $listino->descrizione = "Catalogo {$supplier->code} importato";
        }

        $listino->save();

        return $listino;
    }

    /**
     * @return array{0:?string, 1:?string}
     */
    private function validityDates(CatalogInspectionResult $inspection): array
    {
        $ranges = $inspection->parsed->sheetStructures[0]['validity_ranges'] ?? [];
        if (count($ranges) !== 1 || ! is_array($ranges[0])) {
            return [null, null];
        }

        return [
            $ranges[0]['valid_from'] ?: null,
            $ranges[0]['valid_to'] ?: null,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function blockingConflicts(CatalogInspectionResult $inspection): array
    {
        return array_values(array_filter(
            $inspection->report['diagnostics']['conflicts'] ?? [],
            static fn (array $conflict): bool => ($conflict['type'] ?? null)
                === 'conflicting_supplier_code_duplicate'
        ));
    }

    /**
     * @param  array<int, string>  $writtenImagePaths
     * @return array{0:?string, 1:?string}
     */
    private function storeImage(
        string $supplierCode,
        ?CatalogImportImage $image,
        array &$writtenImagePaths,
    ): array {
        if ($image === null) {
            return [null, null];
        }

        if (
            $image->content === null
            || $image->contentHash === null
            || hash('sha256', $image->content) !== $image->contentHash
        ) {
            throw new RuntimeException(
                "Immagine certa non leggibile alla riga {$image->sourceRow}."
            );
        }

        $extension = $this->safeImageExtension($image);
        if ($extension === null) {
            throw new RuntimeException(
                "Formato immagine non supportato alla riga {$image->sourceRow}."
            );
        }

        $path = sprintf(
            'cataloghi/%s/%s.%s',
            strtolower($supplierCode),
            $image->contentHash,
            $extension
        );
        $disk = Storage::disk('public');

        if (! $disk->exists($path)) {
            if (! $disk->put($path, $image->content)) {
                throw new RuntimeException(
                    "Scrittura immagine non riuscita alla riga {$image->sourceRow}."
                );
            }

            $writtenImagePaths[] = $path;
        }

        return [$path, $image->contentHash];
    }

    private function safeImageExtension(CatalogImportImage $image): ?string
    {
        $mimeExtensions = [
            'image/gif' => 'gif',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];
        $mimeType = strtolower((string) $image->mimeType);

        if (isset($mimeExtensions[$mimeType])) {
            return $mimeExtensions[$mimeType];
        }

        $extension = strtolower((string) $image->extension);

        return in_array($extension, ['gif', 'jpeg', 'jpg', 'png', 'webp'], true)
            ? ($extension === 'jpeg' ? 'jpg' : $extension)
            : null;
    }

    /**
     * @return array<int, string>
     */
    private function warnings(CatalogInspectionResult $inspection): array
    {
        $warnings = [
            ...$inspection->parsed->warnings,
            ...$inspection->parsed->errors,
        ];

        foreach ($inspection->parsed->rows as $row) {
            foreach ($row->warnings as $warning) {
                $warnings[] = "{$row->sourceSheet}:{$row->sourceRow} {$warning}";
            }

            foreach ($row->errors as $error) {
                $warnings[] = "{$row->sourceSheet}:{$row->sourceRow} ignored: {$error}";
            }
        }

        foreach ($inspection->report['diagnostics']['conflicts'] ?? [] as $conflict) {
            if (($conflict['type'] ?? null) !== 'conflicting_supplier_code_duplicate') {
                $warnings[] = sprintf(
                    '%s: %s',
                    (string) ($conflict['type'] ?? 'conflict'),
                    (string) ($conflict['code'] ?? $conflict['supplier_code'] ?? '')
                );
            }
        }

        return array_values(array_unique($warnings));
    }

    /**
     * @param  array<int, array<string, mixed>>  $blockingConflicts
     * @return array<string, mixed>
     */
    private function summary(
        CatalogInspectionResult $inspection,
        array $blockingConflicts = [],
    ): array {
        return [
            'summary' => $inspection->report['summary'] ?? [],
            'diagnostics' => [
                'duplicate_supplier_codes' => $inspection->report['diagnostics']['duplicate_supplier_codes'] ?? [],
                'duplicate_customer_article_codes' => $inspection->report['diagnostics']['duplicate_customer_article_codes'] ?? [],
                'possible_code_changes' => $inspection->report['diagnostics']['possible_code_changes'] ?? [],
                'conflicts' => $inspection->report['diagnostics']['conflicts'] ?? $blockingConflicts,
                'unassociated_images' => $inspection->report['diagnostics']['unassociated_images'] ?? [],
                'invalid_rows' => $inspection->report['diagnostics']['invalid_rows'] ?? [],
            ],
        ];
    }
}
