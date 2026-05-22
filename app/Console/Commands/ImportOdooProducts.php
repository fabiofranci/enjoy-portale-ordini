<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Odoo\ProductImportService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

final class ImportOdooProducts extends Command
{
    protected $signature = 'odoo:import-products
        {--since= : Importa solo i prodotti modificati dopo questo timestamp}
        {--chunk=200 : Numero di record Odoo per batch}';

    protected $description = 'Importa i prodotti da Odoo in Laravel.';

    public function handle(ProductImportService $service): int
    {
        $startedAt = microtime(true);

        try {
            $since = $this->resolveSinceOption();
            $chunkSize = $this->resolveChunkSize();

            $this->info('Avvio import prodotti da Odoo...');

            if ($since !== null) {
                $this->line('Filtro write_date: ' . $since->format('Y-m-d H:i:s'));
            }

            $stats = $service->import($since, $chunkSize);
            $duration = microtime(true) - $startedAt;

            $this->newLine();
            $this->info('Import completato.');
            $this->line("Prodotti importati: {$stats['imported']}");
            $this->line("Prodotti aggiornati: {$stats['updated']}");
            $this->line("Prodotti processati: {$stats['processed']}");
            $this->line("Errori: {$stats['errors']}");
            $this->line('Tempo esecuzione: ' . number_format($duration, 2) . 's');

            if ($stats['invalid_categories'] > 0) {
                $this->warn("Prodotti con categoria non valida o non sincronizzata: {$stats['invalid_categories']}");
            }

            if ($stats['skipped'] > 0) {
                $this->warn("Prodotti saltati: {$stats['skipped']}");
            }

            return self::SUCCESS;
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error('Import prodotti fallito: ' . $exception->getMessage());
            $this->error('Controlla i log applicativi per i dettagli.');

            return self::FAILURE;
        }
    }

    private function resolveSinceOption(): ?CarbonImmutable
    {
        $since = $this->option('since');

        if (!is_string($since) || trim($since) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($since);
        } catch (Throwable $exception) {
            throw new InvalidArgumentException('Il valore di --since non e` una data valida.', 0, $exception);
        }
    }

    private function resolveChunkSize(): int
    {
        $chunkSize = (int) $this->option('chunk');

        if ($chunkSize < 1) {
            throw new InvalidArgumentException('Il valore di --chunk deve essere maggiore di zero.');
        }

        return $chunkSize;
    }
}
