<?php

declare(strict_types=1);

namespace App\Services\Imports\Catalog\Data;

final readonly class CatalogImportImage
{
    public function __construct(
        public string $sourceCell,
        public int $sourceRow,
        public string $name,
        public ?string $extension,
        public ?string $mimeType,
        public ?string $contentHash,
        public int $width,
        public int $height,
        public ?string $content = null,
    ) {}

    /**
     * @return array<string, int|string|null>
     */
    public function toArray(): array
    {
        return [
            'source_cell' => $this->sourceCell,
            'source_row' => $this->sourceRow,
            'name' => $this->name,
            'extension' => $this->extension,
            'mime_type' => $this->mimeType,
            'content_hash' => $this->contentHash,
            'width' => $this->width,
            'height' => $this->height,
        ];
    }
}
