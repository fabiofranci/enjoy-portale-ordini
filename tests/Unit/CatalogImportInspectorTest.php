<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Imports\Catalog\CatalogImportInspector;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

final class CatalogImportInspectorTest extends TestCase
{
    /** @var array<int, string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    public function test_ica_profile_normalizes_codes_prices_packaging_and_exact_row_image(): void
    {
        $file = $this->icaFixture(withImage: true);
        $inspector = app(CatalogImportInspector::class);
        $first = $inspector->inspect('ICA', $file, 'scuole');
        $second = $inspector->inspect('ICA', $file, 'scuole');

        $this->assertSame($first->report, $second->report);
        $this->assertSame(2, $first->report['summary']['rows_normalized']);
        $this->assertSame(3, $first->report['summary']['images_found']);
        $this->assertSame(1, $first->report['summary']['images_associated']);
        $this->assertSame(2, $first->report['summary']['images_unassociated']);
        $this->assertEqualsCanonicalizing(
            ['additional_image_on_product_row', 'no_exact_product_row'],
            array_column($first->parsed->unassociatedImages, 'reason')
        );

        $row = $first->parsed->rows[0];
        $this->assertSame('ICA-001', $row->supplierCode);
        $this->assertSame('ICA-001', $row->customerArticleCode);
        $this->assertSame(12.34567, $row->sourcePrice);
        $this->assertNull($row->sourcePriceUnit);
        $this->assertNull($row->grossPrice);
        $this->assertNotNull($row->image);
        $this->assertNotNull($row->image->contentHash);
        $this->assertSame('A6', $row->image->sourceCell);
        $this->assertCount(2, $row->packagings);
        $this->assertSame('CF', $row->packagings[0]->fromUnit);
        $this->assertSame('NR', $row->packagings[0]->toUnit);
        $this->assertSame(6.0, $row->packagings[0]->multiplier);
        $this->assertSame($first->parsed->rows[0]->sourceHash, $second->parsed->rows[0]->sourceHash);
    }

    public function test_ica_without_image_is_reported_and_unknown_profile_is_rejected(): void
    {
        $file = $this->icaFixture(withImage: false);
        $result = app(CatalogImportInspector::class)->inspect('ICA', $file, 'scuole');

        $this->assertSame(0, $result->report['summary']['images_found']);
        $this->assertSame(0, $result->report['summary']['images_associated']);
        $this->assertNull($result->parsed->rows[0]->image);

        $this->expectException(InvalidArgumentException::class);
        app(CatalogImportInspector::class)->inspect('ICA', $file, 'ristorazione');
    }

    public function test_igroup_rules_cover_codes_duplicates_ambiguity_prices_units_and_packaging(): void
    {
        $file = $this->igroupFixture();
        $inspector = app(CatalogImportInspector::class);
        $first = $inspector->inspect('IGROUP', $file);
        $second = $inspector->inspect('IGROUP', $file);

        $this->assertSame($first->report, $second->report);
        $this->assertSame(9, $first->report['summary']['rows_read']);
        $this->assertSame(8, $first->report['summary']['rows_valid']);
        $this->assertSame(1, $first->report['summary']['rows_invalid']);
        $this->assertSame(1, $first->report['summary']['duplicate_supplier_codes']);
        $this->assertSame(3, $first->report['summary']['duplicate_customer_article_codes']);
        $this->assertSame(4, $first->report['summary']['possible_code_changes']);
        $this->assertSame(2, $first->report['summary']['conflicts']);
        $this->assertSame(
            1,
            $first->report['sheet_structures'][0]['code_rule_counts']['customer_code_equal']
        );
        $this->assertCount(2, array_filter(
            $first->parsed->rows,
            static fn ($row): bool => $row->supplierCode === 'DUP-S'
        ));
        $supplierDuplicate = collect($first->report['diagnostics']['duplicate_supplier_codes'])
            ->firstWhere('code', 'DUP-S');
        $this->assertSame('identical', $supplierDuplicate['kind']);
        $this->assertSame([6, 7], $supplierDuplicate['source_rows']);

        $blankCustomerCode = $first->parsed->rows[0];
        $this->assertSame('SUP-1', $blankCustomerCode->supplierCode);
        $this->assertSame('SUP-1', $blankCustomerCode->customerArticleCode);
        $this->assertSame(4.5, $blankCustomerCode->sourcePrice);
        $this->assertSame(10.0, $blankCustomerCode->grossPrice);
        $this->assertSame(55.0, $blankCustomerCode->discountPercentage);
        $this->assertSame('NR', $blankCustomerCode->salesUnit);
        $this->assertSame('NR', $blankCustomerCode->sourcePriceUnit);
        $this->assertCount(1, $blankCustomerCode->packagings);
        $this->assertSame('CT', $blankCustomerCode->packagings[0]->fromUnit);
        $this->assertSame('NR', $blankCustomerCode->packagings[0]->toUnit);
        $this->assertSame(12.0, $blankCustomerCode->packagings[0]->multiplier);
        $this->assertTrue($blankCustomerCode->packagings[0]->mandatory);
        $this->assertSame('[REDACTED]', $blankCustomerCode->rawData['customer_id']);
        $this->assertSame('[REDACTED]', $blankCustomerCode->rawData['customer_name']);
        $this->assertSame('[REDACTED]', $blankCustomerCode->rawData['price_list_name']);

        $changed = $first->parsed->rows[1];
        $this->assertSame('NEW-1', $changed->supplierCode);
        $this->assertSame('OLD-1', $changed->customerArticleCode);
        $this->assertContains('possible_supplier_code_change', $changed->warnings);

        $ambiguous = collect($first->report['diagnostics']['possible_code_changes'])
            ->firstWhere('supplier_code', 'NEW-2');
        $this->assertSame('ambiguous', $ambiguous['status']);

        $invalid = $first->parsed->rows[8];
        $this->assertContains('supplier_code_missing', $invalid->errors);
        $this->assertContains('discount_invalid', $invalid->warnings);
        $this->assertNull($invalid->supplierCode);
        $this->assertNull($invalid->customerArticleCode);
        $this->assertSame($first->parsed->rows[0]->sourceHash, $second->parsed->rows[0]->sourceHash);
    }

    private function icaFixture(bool $withImage): string
    {
        $file = $this->tempPath('catalog_ica_', '.xlsx');
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([
            [],
            ['Linea test'],
            [],
            ['Immagine', 'Codice prodotto', 'Descrizione', 'Confezionamento', null, 'Prezzo per unita di vendita', 'Prezzo al cartone'],
            [],
            [null, "'ICA-001", 'Prodotto ICA uno', '6pz per confezione, 4cf per cartone', null, 12.34567, 49.38268],
            [null, 'ICA-002', 'Prodotto ICA due', '1pz per cartone', null, '1,25', '1,25'],
        ]);

        if ($withImage) {
            $image = $this->tempPath('catalog_image_', '.png');
            file_put_contents(
                $image,
                base64_decode(
                    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
                    true
                )
            );
            $this->tempFiles[] = $image;

            foreach ([
                ['Prodotto ICA uno', 'A6'],
                ['Immagine aggiuntiva prodotto', 'A6'],
                ['Immagine sezione', 'A2'],
            ] as [$name, $coordinates]) {
                $drawing = new Drawing;
                $drawing->setName($name);
                $drawing->setPath($image);
                $drawing->setCoordinates($coordinates);
                $drawing->setWorksheet($sheet);
            }
        }

        (new Xlsx($spreadsheet))->save($file);
        $this->tempFiles[] = $file;

        return $file;
    }

    private function igroupFixture(): string
    {
        $headers = [
            'id',
            'Cod. Cliente',
            'Cliente',
            'Cod. Listino',
            'Listino',
            'Cod. Articolo',
            'Articolo',
            'UMprezzo',
            'Prezzo',
            'Sconto1',
            'Sconto2',
            'Sconto3',
            'Sconto4',
            'Prezzo Netto',
            'Cod. Art. Cliente',
            'UM',
            'Imballo',
            'SubImballo',
            'ExtImbTass',
            'ExtSubImbTass',
        ];
        $rows = [
            [1, 'C-1', 'Cliente Test', 403, 'Listino Test', 'SUP-1', 'Prodotto uno', 'NR', '10,00', 50, 10, null, null, '4.50', null, 'NR', 12, 1, -1, 0],
            [2, 'C-1', 'Cliente Test', 403, 'Listino Test', 'NEW-1', 'Prodotto cambio', 'CF', 2.0, 0, null, null, null, 2.0, 'OLD-1', 'CF', 1, 1, 0, 0],
            [3, 'C-1', 'Cliente Test', 403, 'Listino Test', 'NEW-2', 'Prodotto ambiguo nuovo', 'NR', 3.0, 0, null, null, null, 3.0, 'OLD-2', 'NR', 1, 1, 0, 0],
            [4, 'C-1', 'Cliente Test', 403, 'Listino Test', 'OLD-2', 'Prodotto ambiguo corrente', 'NR', 3.0, 0, null, null, null, 3.0, null, 'NR', 1, 1, 0, 0],
            [5, 'C-1', 'Cliente Test', 403, 'Listino Test', 'DUP-S', 'Duplicato esatto', 'CT', 4.0, 0, null, null, null, 4.0, null, 'CT', 1, 1, 0, 0],
            [6, 'C-1', 'Cliente Test', 403, 'Listino Test', 'DUP-S', 'Duplicato esatto', 'CT', 4.0, 0, null, null, null, 4.0, 'DUP-S', 'CT', 1, 1, 0, 0],
            [7, 'C-1', 'Cliente Test', 403, 'Listino Test', 'SUP-A', 'Cliente duplicato A', 'NR', 5.0, 0, null, null, null, 5.0, 'CUST-DUP', 'NR', 1, 1, 0, 0],
            [8, 'C-1', 'Cliente Test', 403, 'Listino Test', 'SUP-B', 'Cliente duplicato B', 'NR', 6.0, 0, null, null, null, 6.0, 'CUST-DUP', 'NR', 1, 1, 0, 0],
            [9, 'C-1', 'Cliente Test', 403, 'Listino Test', null, 'Senza codici', 'NR', 1.0, 'bad', null, null, null, 1.0, null, 'NR', 1, 1, 0, 0],
        ];

        $file = $this->tempPath('catalog_igroup_', '.xlsx');
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([$headers, ...$rows]);
        (new Xlsx($spreadsheet))->save($file);
        $this->tempFiles[] = $file;

        return $file;
    }

    private function tempPath(string $prefix, string $extension): string
    {
        $base = tempnam(sys_get_temp_dir(), $prefix);
        unlink($base);

        return $base.$extension;
    }
}
