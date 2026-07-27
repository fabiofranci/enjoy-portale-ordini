<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Imports\Catalog\CatalogImportInspector;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

final class InspectCatalogImport extends Command
{
    protected $signature = 'catalog:inspect-import
        {--supplier= : Fornitore sorgente: ICA o IGROUP}
        {--profile= : Profilo del tracciato, obbligatorio per ICA}
        {--file= : Percorso del file CSV, XLS o XLSX}
        {--json= : Percorso locale facoltativo per il report JSON}';

    protected $description = 'Analizza un listino fornitore in sola lettura, senza accesso al database.';

    public function handle(CatalogImportInspector $inspector): int
    {
        $supplier = trim((string) $this->option('supplier'));
        $file = trim((string) $this->option('file'));
        $profile = $this->option('profile');

        if ($supplier === '' || $file === '') {
            $this->error('Le opzioni --supplier e --file sono obbligatorie.');

            return self::FAILURE;
        }

        try {
            $result = $inspector->inspect(
                supplier: $supplier,
                filePath: $file,
                profile: is_string($profile) && trim($profile) !== '' ? $profile : null,
            );

            $jsonPath = trim((string) $this->option('json'));
            if ($jsonPath !== '') {
                $this->writeJsonReport($jsonPath, $result->report);
            }
        } catch (Throwable $exception) {
            $this->error('Analisi catalogo fallita: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Analisi diagnostica completata. Nessuna scrittura al database eseguita.');
        $this->line('File: '.$result->parsed->sourceFile);
        $this->line('SHA-256: '.$result->parsed->fileHash);
        $this->newLine();

        $this->table(
            ['Voce', 'Totale'],
            array_map(
                static fn (string $label, mixed $value): array => [$label, (string) $value],
                array_keys($result->report['summary']),
                $result->report['summary']
            )
        );

        if ($jsonPath !== '') {
            $this->newLine();
            $this->line('Report JSON: '.(realpath($jsonPath) ?: $jsonPath));
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function writeJsonReport(string $path, array $report): void
    {
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException("Impossibile creare la directory del report: {$directory}");
        }

        $json = json_encode(
            $report,
            JSON_THROW_ON_ERROR
                | JSON_PRETTY_PRINT
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_PRESERVE_ZERO_FRACTION
        );

        if (file_put_contents($path, $json.PHP_EOL) === false) {
            throw new RuntimeException("Impossibile scrivere il report JSON: {$path}");
        }
    }
}
