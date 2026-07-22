<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Listino;
use App\Models\Product;
use App\Services\Imports\ScuoleListinoImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ScuoleListinoImportDryRunTest extends TestCase
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

    public function test_dry_run_compares_with_database_idempotently_and_does_not_write(): void
    {
        $categoria = Categoria::create([
            'nome' => 'Detergenti',
            'percentuale_ricarico' => 0,
        ]);
        $altraCategoria = Categoria::create([
            'nome' => 'Carta',
            'percentuale_ricarico' => 0,
        ]);
        $listino = Listino::create([
            'nome_listino' => 'Scuole',
            'tipo' => 'vendita',
            'sconto_percentuale' => 0,
            'valido_dal' => now()->toDateString(),
        ]);
        $unchanged = Product::create([
            'nome' => 'Prodotto invariato',
            'codice' => 'EXIST-1',
            'categoria_id' => $categoria->id,
            'unita_misura' => 'NR',
            'disponibile' => true,
        ]);
        $toUpdate = Product::create([
            'nome' => 'Nome vecchio',
            'codice' => 'EXIST-2',
            'categoria_id' => $altraCategoria->id,
            'unita_misura' => 'CF',
            'disponibile' => true,
        ]);

        $listino->products()->attach($unchanged->id, [
            'prezzo_lordo' => '1.23456',
            'sconto_percentuale' => '0',
            'prezzo' => '1.23456',
            'iva_percentuale' => '22',
            'ordinabile' => true,
            'prezzo_sorgente' => '1.23456',
            'unita_prezzo_sorgente' => 'NR',
        ]);
        $listino->products()->attach($toUpdate->id, [
            'prezzo_lordo' => '4.00000',
            'sconto_percentuale' => '0',
            'prezzo' => '4.00000',
            'iva_percentuale' => '22',
            'ordinabile' => true,
            'prezzo_sorgente' => '4.00000',
            'unita_prezzo_sorgente' => 'CF',
        ]);

        $file = $this->tempCsv([
            ['CAT', 'Codice', 'Descrizione', 'Prezzo Lordo', 'IVA %', 'Sconto %', 'Prezzo Netto', 'UM'],
            ['Detergenti', 'EXIST-1', 'Prodotto invariato', '1,23456', '22', '', '1,23456', 'NR'],
            ['Detergenti', 'EXIST-2', 'Nome nuovo', '5,00000', '22', '', '5,00000', 'NR'],
            ['Nuova categoria', 'NEW-1', 'Prodotto nuovo', '2,00000', '22', '', '2,00000', 'NR'],
        ]);

        $before = $this->tableCounts();
        $service = app(ScuoleListinoImportService::class);
        $first = $service->dryRun($file, ['listino' => 'Scuole']);
        $second = $service->dryRun($file, ['listino' => 'Scuole']);

        $this->assertSame($before, $this->tableCounts());
        $this->assertSame($first['summary'], $second['summary']);
        $this->assertSame(1, $first['summary']['categorie_nuove']);
        $this->assertSame(1, $first['summary']['prodotti_nuovi']);
        $this->assertSame(1, $first['summary']['prodotti_da_aggiornare']);
        $this->assertSame(1, $first['summary']['prodotti_invariati']);
        $this->assertSame(1, $first['summary']['prezzi_nuovi']);
        $this->assertSame(1, $first['summary']['prezzi_da_aggiornare']);
        $this->assertSame(1, $first['summary']['prezzi_invariati']);
    }

    public function test_command_requires_dry_run_and_dry_run_does_not_write(): void
    {
        $file = $this->tempCsv([
            ['CAT', 'Codice', 'Descrizione', 'Prezzo Netto', 'UM'],
            ['Detergenti', 'CMD-1', 'Prodotto comando', '3,00000', 'NR'],
        ]);

        $this->artisan('scuole:import-listino', ['file' => $file])
            ->expectsOutput('Aggiungi --dry-run per analizzare oppure --execute per importare realmente.')
            ->assertFailed();

        $before = $this->tableCounts();

        $this->artisan('scuole:import-listino', [
            'file' => $file,
            '--dry-run' => true,
            '--listino' => 'Scuole',
        ])
            ->expectsOutput('Dry-run listino Scuole completato. Nessuna scrittura eseguita.')
            ->assertSuccessful();

        $this->assertSame($before, $this->tableCounts());
    }

    private function tempCsv(array $rows): string
    {
        $file = tempnam(sys_get_temp_dir(), 'scuole_feature_') . '.csv';
        $handle = fopen($file, 'w');

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        fclose($handle);
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
            'centro_costo_listino' => DB::table('centro_costo_listino')->count(),
        ];
    }
}
