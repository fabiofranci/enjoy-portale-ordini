<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CatalogInspectImportCommandTest extends TestCase
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

    public function test_diagnostic_command_executes_no_database_queries_and_exports_redacted_json(): void
    {
        $file = $this->tempCsv([
            [
                'id',
                'Cod. Cliente',
                'Cliente',
                'Cod. Listino',
                'Listino',
                'Cod. Articolo',
                'Articolo',
                'UMprezzo',
                'Prezzo',
                'Prezzo Netto',
                'Cod. Art. Cliente',
                'UM',
                'Imballo',
                'SubImballo',
            ],
            [1, 'SECRET-CODE', 'Secret Customer', 403, 'Secret List', 'ART-1', 'Prodotto test', 'NR', '2,50', '2.50', '', 'NR', 6, 1],
        ]);
        $jsonBase = tempnam(sys_get_temp_dir(), 'catalog_report_');
        unlink($jsonBase);
        $json = $jsonBase.'.json';
        $this->tempFiles[] = $json;
        $queries = [];

        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->artisan('catalog:inspect-import', [
            '--supplier' => 'IGROUP',
            '--file' => $file,
            '--json' => $json,
        ])
            ->expectsOutput('Analisi diagnostica completata. Nessuna scrittura al database eseguita.')
            ->assertSuccessful();

        $this->assertSame([], $queries);
        $this->assertFileExists($json);

        $report = json_decode(file_get_contents($json), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('read_only_diagnostic', $report['mode']);
        $this->assertSame(1, $report['summary']['rows_normalized']);
        $this->assertSame('[REDACTED]', $report['rows'][0]['raw_data']['customer_id']);
        $this->assertSame('[REDACTED]', $report['rows'][0]['raw_data']['customer_name']);
        $this->assertSame('[REDACTED]', $report['rows'][0]['raw_data']['price_list_name']);
        $this->assertStringNotContainsString('SECRET-CODE', file_get_contents($json));
        $this->assertStringNotContainsString('Secret Customer', file_get_contents($json));
        $this->assertStringNotContainsString('Secret List', file_get_contents($json));
    }

    public function test_ica_diagnostic_command_executes_no_database_queries(): void
    {
        $file = $this->tempCsv([
            ['CAT', 'Codice', 'Descrizione', 'Prezzo Netto', 'UM'],
            ['Detergenti', 'ICA-1', 'Prodotto ICA', '3,50', 'NR'],
        ]);
        $queries = [];

        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->artisan('catalog:inspect-import', [
            '--supplier' => 'ICA',
            '--profile' => 'scuole',
            '--file' => $file,
        ])
            ->expectsOutput('Analisi diagnostica completata. Nessuna scrittura al database eseguita.')
            ->assertSuccessful();

        $this->assertSame([], $queries);
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     */
    private function tempCsv(array $rows): string
    {
        $base = tempnam(sys_get_temp_dir(), 'catalog_command_');
        unlink($base);
        $file = $base.'.csv';
        $handle = fopen($file, 'w');

        foreach ($rows as $row) {
            fputcsv($handle, $row, ';');
        }

        fclose($handle);
        $this->tempFiles[] = $file;

        return $file;
    }
}
