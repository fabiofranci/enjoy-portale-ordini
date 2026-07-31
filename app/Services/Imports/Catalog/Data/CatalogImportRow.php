<?php

declare(strict_types=1);

namespace App\Services\Imports\Catalog\Data;

final readonly class CatalogImportRow
{
    /**
     * @param  array<int, CatalogPackaging>  $packagings
     * @param  array<string, mixed>  $rawData
     * @param  array<int, string>  $warnings
     * @param  array<int, string>  $errors
     */
    public function __construct(
        public string $supplier,
        public ?string $sourceProfile,
        public string $sourceFile,
        public string $sourceSheet,
        public int $sourceRow,
        public ?string $supplierCode,
        public ?string $customerArticleCode,
        public ?string $description,
        public ?string $category,
        public ?string $categoryCode,
        public ?string $parentCategory,
        public ?string $parentCategoryCode,
        public ?string $salesUnit,
        public ?float $sourcePrice,
        public ?string $sourcePriceUnit,
        public ?float $grossPrice,
        public ?float $discountPercentage,
        public ?float $vatPercentage,
        public ?float $cartonPrice,
        public bool $orderable,
        public ?string $nonOrderableReason,
        public array $packagings,
        public ?CatalogImportImage $image,
        public ?string $externalSourceId,
        public array $rawData,
        public string $sourceHash,
        public array $warnings = [],
        public array $errors = [],
    ) {}

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'supplier' => $this->supplier,
            'source_profile' => $this->sourceProfile,
            'source_file' => $this->sourceFile,
            'source_sheet' => $this->sourceSheet,
            'source_row' => $this->sourceRow,
            'supplier_code' => $this->supplierCode,
            'customer_article_code' => $this->customerArticleCode,
            'description' => $this->description,
            'category' => $this->category,
            'category_code' => $this->categoryCode,
            'parent_category' => $this->parentCategory,
            'parent_category_code' => $this->parentCategoryCode,
            'sales_unit' => $this->salesUnit,
            'source_price' => $this->sourcePrice,
            'source_price_unit' => $this->sourcePriceUnit,
            'gross_price' => $this->grossPrice,
            'discount_percentage' => $this->discountPercentage,
            'vat_percentage' => $this->vatPercentage,
            'carton_price' => $this->cartonPrice,
            'orderable' => $this->orderable,
            'non_orderable_reason' => $this->nonOrderableReason,
            'packagings' => array_map(
                static fn (CatalogPackaging $packaging): array => $packaging->toArray(),
                $this->packagings
            ),
            'image' => $this->image?->toArray(),
            'external_source_id' => $this->externalSourceId,
            'raw_data' => $this->rawData,
            'source_hash' => $this->sourceHash,
            'warnings' => $this->warnings,
            'errors' => $this->errors,
        ];
    }
}
