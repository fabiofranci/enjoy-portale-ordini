<?php

declare(strict_types=1);

namespace App\Services\Imports\Catalog;

use App\Services\Imports\Catalog\Data\CatalogImportImage;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\Worksheet\BaseDrawing;
use Throwable;

final class SpreadsheetDocumentReader
{
    /**
     * @return array{
     *     path:string,
     *     file:string,
     *     type:string,
     *     file_hash:string,
     *     sheets:array<int, array<string, mixed>>
     * }
     */
    public function read(string $filePath): array
    {
        $realPath = realpath($filePath);

        if ($realPath === false || ! is_file($realPath)) {
            throw new InvalidArgumentException("File non trovato: {$filePath}");
        }

        $extension = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));

        if ($extension === 'csv') {
            $reader = new Csv;
            $reader->setDelimiter($this->detectCsvDelimiter($realPath));
            $reader->setEnclosure('"');
            $reader->setSheetIndex(0);
            $reader->setReadDataOnly(false);
        } elseif (in_array($extension, ['xls', 'xlsx'], true)) {
            $reader = IOFactory::createReaderForFile($realPath);
            $reader->setReadDataOnly(false);
        } else {
            throw new InvalidArgumentException('Formato non supportato. Usare CSV, XLS o XLSX.');
        }

        $spreadsheet = $reader->load($realPath);
        $sheets = [];

        foreach ($spreadsheet->getWorksheetIterator() as $worksheet) {
            $rows = [];

            foreach ($worksheet->toArray(null, true, true, false) as $index => $row) {
                $rows[$index + 1] = $row;
            }

            $formulaCount = 0;
            foreach ($worksheet->getCellCollection()->getCoordinates() as $coordinate) {
                $value = $worksheet->getCell($coordinate)->getValue();
                if (is_string($value) && str_starts_with($value, '=')) {
                    $formulaCount++;
                }
            }

            $images = [];
            foreach ($worksheet->getDrawingCollection() as $drawing) {
                $images[] = $this->imageMetadata($drawing);
            }

            $tables = [];
            foreach ($worksheet->getTableCollection() as $table) {
                $tables[] = [
                    'name' => $table->getName(),
                    'range' => $table->getRange(),
                ];
            }

            $sheets[] = [
                'name' => $worksheet->getTitle(),
                'rows' => $rows,
                'highest_row' => $worksheet->getHighestRow(),
                'highest_data_row' => $worksheet->getHighestDataRow(),
                'highest_column' => $worksheet->getHighestColumn(),
                'highest_data_column' => $worksheet->getHighestDataColumn(),
                'used_range' => 'A1:'.$worksheet->getHighestDataColumn().$worksheet->getHighestDataRow(),
                'merged_cells' => array_values($worksheet->getMergeCells()),
                'formula_count' => $formulaCount,
                'images' => $images,
                'tables' => $tables,
            ];
        }

        return [
            'path' => $realPath,
            'file' => basename($realPath),
            'type' => $extension,
            'file_hash' => hash_file('sha256', $realPath),
            'sheets' => $sheets,
        ];
    }

    private function imageMetadata(BaseDrawing $drawing): CatalogImportImage
    {
        $sourceCell = $drawing->getCoordinates();
        preg_match('/(\d+)$/', $sourceCell, $matches);
        $sourceRow = isset($matches[1]) ? (int) $matches[1] : 0;
        $extension = null;
        $mimeType = null;
        $contentHash = null;
        $content = null;

        try {
            if (method_exists($drawing, 'getExtension')) {
                $extension = $drawing->getExtension() ?: null;
            }

            if (method_exists($drawing, 'getImageMimeType')) {
                $mimeType = $drawing->getImageMimeType() ?: null;
            }

            if (method_exists($drawing, 'getPath')) {
                $imageContent = @file_get_contents($drawing->getPath());
                if ($imageContent !== false) {
                    $content = $imageContent;
                    $contentHash = hash('sha256', $imageContent);
                }
            }
        } catch (Throwable) {
            // The image remains reportable even when its binary payload is unreadable.
        }

        return new CatalogImportImage(
            sourceCell: Coordinate::coordinateFromString($sourceCell)[0].$sourceRow,
            sourceRow: $sourceRow,
            name: $drawing->getName(),
            extension: $extension,
            mimeType: $mimeType,
            contentHash: $contentHash,
            width: $drawing->getWidth(),
            height: $drawing->getHeight(),
            content: $content,
        );
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
}
