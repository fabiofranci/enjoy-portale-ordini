<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Listino;
use App\Models\Product;
use App\Services\Imports\ScuoleListinoImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Tests\TestCase;

class ScuoleListinoImportExecuteTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_execute_import_creates_records_non_orderable_products_and_valid_packaging(): void
    {
        $result = app(ScuoleListinoImportService::class)->import($this->schoolWorkbook(), [
            'listino' => 'Scuole',
            'valid_from' => '2026-01-01',
            'valid_to' => '2026-12-31',
        ]);

        $this->assertSame(14, $result['summary']['product_rows']);
        $this->assertSame(12, $result['summary']['prodotti_non_ordinabili']);
        $this->assertSame(14, $result['write_summary']['prodotti_create']);
        $this->assertSame(14, $result['write_summary']['prezzi_create']);
        $this->assertSame(1, $result['write_summary']['packaging_create']);
        $this->assertSame(1, $result['write_summary']['packaging_incompleti_ignorati']);

        $listino = Listino::where('nome_listino', 'Scuole')->firstOrFail();
        $this->assertSame('vendita', $listino->tipo);
        $this->assertTrue($listino->attivo);
        $this->assertSame('2026-01-01', $listino->valido_dal->toDateString());
        $this->assertSame('2026-12-31', $listino->valido_al->toDateString());
        $this->assertDatabaseCount('Prodotti', 14);
        $this->assertDatabaseCount('listino_prodotto', 14);

        $validProduct = Product::where('codice', 'SKU-VALID')->firstOrFail();
        $validPivot = DB::table('listino_prodotto')
            ->where('listino_id', $listino->id)
            ->where('product_id', $validProduct->id)
            ->first();
        $packaging = DB::table('product_packagings')
            ->where('product_id', $validProduct->id)
            ->where('from_unit', 'CT')
            ->where('to_unit', 'NR')
            ->first();

        $this->assertNotNull($validPivot);
        $this->assertNotNull($packaging);
        $this->assertSame(30.0, round((float) $validPivot->prezzo * (float) $packaging->multiplier, 2));

        $this->assertSame(12, DB::table('listino_prodotto')->where('ordinabile', false)->count());
        $this->assertDatabaseHas('listino_prodotto', [
            'listino_id' => $listino->id,
            'ordinabile' => false,
            'motivo_non_ordinabile' => 'prezzo_trattini',
            'prezzo_sorgente' => null,
        ]);
        $this->assertDatabaseMissing('product_packagings', [
            'product_id' => Product::where('codice', 'SKU-INCOMPLETE')->firstOrFail()->id,
        ]);
    }

    public function test_execute_import_is_idempotent_on_second_run(): void
    {
        $file = $this->schoolWorkbook();
        $service = app(ScuoleListinoImportService::class);
        $service->import($file, [
            'listino' => 'Scuole',
            'valid_from' => '2026-01-01',
            'valid_to' => '2026-12-31',
        ]);
        $before = $this->tableCounts();

        $second = $service->import($file, [
            'listino' => 'Scuole',
            'valid_from' => '2026-01-01',
            'valid_to' => '2026-12-31',
        ]);

        $this->assertSame($before, $this->tableCounts());
        $this->assertSame(0, $second['write_summary']['prodotti_create']);
        $this->assertSame(14, $second['write_summary']['prodotti_invariati']);
        $this->assertSame(14, $second['write_summary']['prezzi_invariati']);
        $this->assertSame(1, $second['write_summary']['packaging_invariati']);
    }

    public function test_execute_import_updates_product_and_price(): void
    {
        $service = app(ScuoleListinoImportService::class);
        $service->import($this->workbook([
            ['CAT', 'Codice', 'Descrizione', 'Prezzo Netto', 'UM', 'Confezionamento', 'Prezzo al cartone'],
            ['Detergenti', 'SKU-UPD', 'Nome iniziale', 2.5, 'NR', '12pz per cartone', 30],
        ]), [
            'listino' => 'Scuole',
            'valid_from' => '2026-01-01',
            'valid_to' => '2026-12-31',
        ]);

        $second = $service->import($this->workbook([
            ['CAT', 'Codice', 'Descrizione', 'Prezzo Netto', 'UM', 'Confezionamento', 'Prezzo al cartone'],
            ['Detergenti', 'SKU-UPD', 'Nome aggiornato', 3, 'NR', '12pz per cartone', 36],
        ]), [
            'listino' => 'Scuole',
            'valid_from' => '2026-01-01',
            'valid_to' => '2026-12-31',
        ]);

        $this->assertSame(1, $second['write_summary']['prodotti_update']);
        $this->assertSame(1, $second['write_summary']['prezzi_update']);
        $this->assertSame('Nome aggiornato', Product::where('codice', 'SKU-UPD')->value('nome'));
        $this->assertSame('3.00000', number_format((float) DB::table('listino_prodotto')->value('prezzo'), 5, '.', ''));
    }

    public function test_execute_import_rolls_back_when_a_write_fails(): void
    {
        Categoria::created(static function (): void {
            throw new RuntimeException('Errore simulato dopo scrittura categoria.');
        });

        try {
            app(ScuoleListinoImportService::class)->import($this->workbook([
                ['CAT', 'Codice', 'Descrizione', 'Prezzo Netto', 'UM'],
                ['Detergenti', 'SKU-ROLLBACK', 'Prodotto rollback', 2.5, 'NR'],
            ]), [
                'listino' => 'Scuole',
                'valid_from' => '2026-01-01',
                'valid_to' => '2026-12-31',
            ]);

            $this->fail('Import completed despite simulated failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Errore simulato dopo scrittura categoria.', $exception->getMessage());
        } finally {
            Categoria::flushEventListeners();
        }

        $this->assertDatabaseCount('Listini', 0);
        $this->assertDatabaseCount('Categorie', 0);
        $this->assertDatabaseCount('Prodotti', 0);
        $this->assertDatabaseCount('listino_prodotto', 0);
    }

    public function test_packaging_with_carton_price_mismatch_is_not_imported(): void
    {
        $result = app(ScuoleListinoImportService::class)->import($this->workbook([
            ['CAT', 'Codice', 'Descrizione', 'Prezzo Netto', 'UM', 'Confezionamento', 'Prezzo al cartone'],
            ['Detergenti', 'SKU-MISMATCH', 'Prodotto mismatch', 2.5, 'NR', '12pz per cartone', 29],
        ]), [
            'listino' => 'Scuole',
            'valid_from' => '2026-01-01',
            'valid_to' => '2026-12-31',
        ]);

        $this->assertSame(0, $result['write_summary']['packaging_create']);
        $this->assertSame(1, $result['write_summary']['packaging_conflittuali']);
        $this->assertSame('prezzo_cartone_non_coerente', $result['packaging']['conflicting'][0]['reason']);
        $this->assertDatabaseCount('product_packagings', 0);
    }

    private function schoolWorkbook(): string
    {
        $rows = [
            ['CAT', 'Codice', 'Descrizione', 'Prezzo Netto', 'UM', 'Confezionamento', 'Prezzo al cartone'],
            ['Detergenti', 'SKU-VALID', 'Prodotto valido', 2.5, 'NR', '12pz per cartone', 30],
            ['Detergenti', 'SKU-INCOMPLETE', 'Packaging incompleto', 1, 'NR', '1pz per cartone', 1],
        ];

        foreach (range(1, 4) as $index) {
            $rows[] = ['Non ordinabili', 'INV-DASH-' . $index, 'Prezzo trattini ' . $index, '---', 'NR', '', ''];
        }

        foreach (range(1, 5) as $index) {
            $rows[] = ['Non ordinabili', 'INV-REF-' . $index, 'Prezzo ref ' . $index, '#REF!', 'NR', '', ''];
        }

        foreach (range(1, 3) as $index) {
            $rows[] = ['Non ordinabili', 'INV-ZERO-' . $index, 'Prezzo zero ' . $index, 0, 'NR', '', ''];
        }

        return $this->workbook($rows);
    }

    private function workbook(array $rows): string
    {
        $file = tempnam(sys_get_temp_dir(), 'scuole_execute_') . '.xlsx';
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->fromArray($rows);
        (new Xlsx($spreadsheet))->save($file);
        $this->tempFiles[] = $file;

        return $file;
    }

    /**
     * @return array<string, int>
     */
    private function tableCounts(): array
    {
        return [
            'Categorie' => DB::table('Categorie')->count(),
            'Prodotti' => DB::table('Prodotti')->count(),
            'Listini' => DB::table('Listini')->count(),
            'listino_prodotto' => DB::table('listino_prodotto')->count(),
            'product_packagings' => DB::table('product_packagings')->count(),
        ];
    }
}
