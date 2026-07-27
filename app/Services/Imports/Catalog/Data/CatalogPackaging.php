<?php

declare(strict_types=1);

namespace App\Services\Imports\Catalog\Data;

final readonly class CatalogPackaging
{
    public function __construct(
        public string $fromUnit,
        public string $toUnit,
        public float $multiplier,
        public string $sourceField,
        public mixed $sourceValue,
        public bool $mandatory = false,
    ) {}

    /**
     * @return array<string, bool|float|string|null>
     */
    public function toArray(): array
    {
        return [
            'from_unit' => $this->fromUnit,
            'to_unit' => $this->toUnit,
            'multiplier' => $this->multiplier,
            'source_field' => $this->sourceField,
            'source_value' => is_scalar($this->sourceValue) ? (string) $this->sourceValue : null,
            'mandatory' => $this->mandatory,
        ];
    }
}
