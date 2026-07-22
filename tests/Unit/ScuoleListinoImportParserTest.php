<?php

namespace Tests\Unit;

use App\Services\Imports\ScuoleListinoImportService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ScuoleListinoImportParserTest extends TestCase
{
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

    public function test_csv_parser_handles_headers_sections_prices_discounts_duplicates_and_packaging(): void
    {
        $file = $this->tempCsv([
            ['sezione', 'SKU', 'nome prodotto', 'prezzo lordo', 'IVA', 'sconto', 'prezzo', 'unità di misura', 'confezionamento', 'imballo', 'subimballo', 'tassativo'],
            ['', '', 'Detergenti', '', '', '', '', '', '', '', '', ''],
            ['', 'SKU-A', 'Prodotto A', '2,00000', '22%', '35+3', '1,23456', 'NR', '', '12', '6', ''],
            ['', 'SKU-A', 'Prodotto A', '2,00000', '22%', '35+3', '1,23456', 'NR', '', '12', '6', ''],
            ['01.01 01.01 Carta', 'SKU-ZERO', 'Prodotto zero', '2,00000', '22', '', '0', 'NR', '', '', '', ''],
            ['Carta', 'SKU-REF', 'Prodotto ref', '2,00000', '22', '', '#REF!', 'NR', '', '', '', ''],
            ['Carta', 'SKU-DASH', 'Prodotto dash', '2,00000', '22', '', '---', 'NR', '', '', '', ''],
            ['Carta', 'SKU-EMPTY', 'Prodotto empty', '2,00000', '22', '', '', 'NR', '', '', '', ''],
            ['Carta', 'SKU-BAD', 'Prodotto bad', '2,00000', '22', '', 'abc', 'NR', '', '', '', ''],
            ['Guanti', 'VELO PW-U-6', 'GUANTI NITRILE S/P TG S PZ 100', '4,77000', '22', '50', '2,38500', 'CF', '', '', '', ''],
            ['Guanti', 'VELO PW-U-6', 'GUANTI NITRILE S/P TG M PZ 100', '4,77000', '22', '50', '2,38500', 'CF', '', '', '', ''],
            ['Carta', 'SKU-TEXT', 'Solo testo confezione', '5,00000', '22', '', '5,00000', 'NR', 'PZ 100', '', '', ''],
            ['Carta', 'SKU-ONE', 'Imballi non significativi', '5,00000', '22', '', '5,00000', 'CT', '', '1', '1', ''],
            ['Carta', 'SKU-TASS', 'Prodotto tassativo', '5,00000', '22', '', '5,00000', 'NR', '', '12', '6', '-1'],
        ]);

        $result = app(ScuoleListinoImportService::class)->parseFile($file);

        $this->assertSame('SKU-A', $result['unique_product_rows'][0]['sku']);
        $this->assertSame('Detergenti', $result['unique_product_rows'][0]['category']['name']);
        $this->assertSame(1.23456, $result['unique_product_rows'][0]['price']['prezzo']);
        $this->assertSame([35.0, 3.0], $result['unique_product_rows'][0]['price']['sconto_componenti']);
        $this->assertSame(36.95, round($result['unique_product_rows'][0]['price']['sconto_percentuale'], 2));
        $this->assertCount(1, $result['duplicates']['identical']);
        $this->assertSame('VELO PW-U-6', $result['duplicates']['conflicting'][0]['sku']);
        $this->assertContains('descrizione', $result['duplicates']['conflicting'][0]['reasons']);

        $invalidReasons = collect($result['prices']['invalid'])->pluck('reason')->all();
        $this->assertContains('prezzo_zero', $invalidReasons);
        $this->assertContains('prezzo_ref', $invalidReasons);
        $this->assertContains('prezzo_trattini', $invalidReasons);
        $this->assertContains('prezzo_vuoto', $invalidReasons);
        $this->assertContains('prezzo_non_numerico', $invalidReasons);

        $this->assertTrue(collect($result['packaging']['valid'])->contains(fn (array $row): bool => $row['from_unit'] === 'CF' && $row['to_unit'] === 'NR' && $row['multiplier'] === 6.0));
        $this->assertTrue(collect($result['packaging']['valid'])->contains(fn (array $row): bool => $row['from_unit'] === 'CT' && $row['to_unit'] === 'CF' && $row['multiplier'] === 12.0));
        $this->assertTrue(collect($result['packaging']['incomplete'])->contains(fn (array $row): bool => $row['reason'] === 'confezionamento_testuale_non_convertito'));
        $this->assertTrue(collect($result['packaging']['incomplete'])->contains(fn (array $row): bool => $row['reason'] === 'imballo_subimballo_non_significativi'));
        $this->assertSame('SKU-TASS', $result['packaging']['tassativi'][0]['sku']);
    }

    public function test_xlsx_parser_reads_equivalent_headers_and_five_decimal_prices(): void
    {
        $file = $this->tempXlsx([
            ['CAT', 'Codice', 'Descrizione', 'Prezzo Netto', 'UM'],
            ['02.01 02.01 Igiene', 'XLSX-1', 'Prodotto XLSX', 12.34567, 'NR'],
        ]);

        $result = app(ScuoleListinoImportService::class)->parseFile($file);

        $this->assertSame('XLSX-1', $result['unique_product_rows'][0]['sku']);
        $this->assertSame('Igiene', $result['unique_product_rows'][0]['category']['name']);
        $this->assertSame(12.34567, $result['unique_product_rows'][0]['price']['prezzo']);
    }

    public function test_xlsx_parser_handles_real_scuole_layout_sections_repeated_headers_merges_and_adjacent_values(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'scuole_real_layout_') . '.xlsx';
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([
            [],
            ['Linea lavanderia'],
            [],
            ['Immagine', 'Codice prodotto', 'Descrizione', 'Confezionamento', null, 'Prezzo per unità vendita', 'Prezzo al cartone'],
            [],
            [null, '10010010', 'FLEX SUPER POLVERE SACCO 10KG', '4pz per cartone', null, 12.34567, '=F6*4'],
            [null, null, 'detersivo in polvere'],
            [],
            ['Linea bagno'],
            ['Immagine', 'Codice prodotto', 'Descrizione', 'Confezionamento', null, 'Prezzo per unità vendita', 'Prezzo al cartone'],
            [null, '44092298', 'DISPENSER IDENTITY SOAP FOAM BIANCO', '1pz per cartone', null, '---', '---'],
            [null, null, 'descrizione estesa dispenser'],
            [null, null, null, null, null, 1.75, '=F13*12'],
            [null, '40075032', 'SALVIETTE UMIDIF. PZ.72 BIMBI', '72pz per confezione, 12cf per cartone'],
            [null, '02098020', null, '10pz per cartone', null, null, null],
            [null, null, 'Con attacco universale e snodo standard', null, null, 17.25, '=F16*10'],
        ]);

        $sheet->mergeCells('F13:F14');
        $sheet->mergeCells('G13:G14');
        (new Xlsx($spreadsheet))->save($file);
        $this->tempFiles[] = $file;

        $result = app(ScuoleListinoImportService::class)->parseFile($file);

        $this->assertCount(2, $result['section_rows']);
        $this->assertSame('Linea lavanderia', $result['section_rows'][0]['category']['name']);
        $this->assertSame('Linea bagno', $result['section_rows'][1]['category']['name']);
        $this->assertCount(4, $result['unique_product_rows']);

        $first = collect($result['unique_product_rows'])->firstWhere('sku', '10010010');
        $this->assertSame(12.34567, $first['price']['prezzo']);
        $this->assertSame(49.38268, $first['price']['prezzo_cartone']);
        $this->assertSame('Linea lavanderia', $first['category']['name']);

        $salviette = collect($result['unique_product_rows'])->firstWhere('sku', '40075032');
        $this->assertSame(1.75, $salviette['price']['prezzo']);
        $this->assertSame(21.0, $salviette['price']['prezzo_cartone']);

        $fallbackDescription = collect($result['unique_product_rows'])->firstWhere('sku', '02098020');
        $this->assertSame('Con attacco universale e snodo standard', $fallbackDescription['description']);
        $this->assertSame(17.25, $fallbackDescription['price']['prezzo']);

        $this->assertTrue(collect($result['prices']['invalid'])->contains(fn (array $row): bool => $row['sku'] === '44092298' && $row['reason'] === 'prezzo_trattini'));
        $this->assertTrue(collect($result['packaging']['valid'])->contains(fn (array $row): bool => $row['sku'] === '10010010' && $row['from_unit'] === 'CT' && $row['to_unit'] === 'NR' && $row['multiplier'] === 4.0));
        $this->assertTrue(collect($result['packaging']['valid'])->contains(fn (array $row): bool => $row['sku'] === '40075032' && $row['from_unit'] === 'CF' && $row['to_unit'] === 'NR' && $row['multiplier'] === 72.0));
        $this->assertTrue(collect($result['packaging']['valid'])->contains(fn (array $row): bool => $row['sku'] === '40075032' && $row['from_unit'] === 'CT' && $row['to_unit'] === 'CF' && $row['multiplier'] === 12.0));
    }

    private function tempCsv(array $rows): string
    {
        $file = tempnam(sys_get_temp_dir(), 'scuole_csv_') . '.csv';
        $handle = fopen($file, 'w');

        foreach ($rows as $row) {
            fputcsv($handle, $row, ';');
        }

        fclose($handle);
        $this->tempFiles[] = $file;

        return $file;
    }

    private function tempXlsx(array $rows): string
    {
        $file = tempnam(sys_get_temp_dir(), 'scuole_xlsx_') . '.xlsx';
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->fromArray($rows);
        (new Xlsx($spreadsheet))->save($file);
        $this->tempFiles[] = $file;

        return $file;
    }
}
