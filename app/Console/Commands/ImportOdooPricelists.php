<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Odoo\PricelistImportService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

final class ImportOdooPricelists extends Command
{
    protected $signature = 'odoo:import-pricelists
        {--since= : Importa solo listini e regole modificati dopo questo timestamp}
        {--chunk=200 : Numero di record Odoo per batch}';

    protected $description = 'Importa product.pricelist e product.pricelist.item da Odoo in Laravel.';

    public function handle(PricelistImportService $service): int
    {
        $startedAt = microtime(true);

        try {
            $since = $this->resolveSinceOption();
            $chunkSize = $this->resolveChunkSize();

            $this->info('Avvio import listini Odoo...');

            if ($since !== null) {
                $this->line('Filtro write_date: ' . $since->format('Y-m-d H:i:s'));
            }

            $stats = $service->import($since, $chunkSize);
            $duration = microtime(true) - $startedAt;

            $this->newLine();
            $this->info('Import completato.');
            $this->line("Listini importati: {$stats['pricelists_imported']}");
            $this->line("Listini aggiornati: {$stats['pricelists_updated']}");
            $this->line("Listini processati: {$stats['pricelists_processed']}");
            $this->line("Regole importate: {$stats['pricelist_items_imported']}");
            $this->line("Regole aggiornate: {$stats['pricelist_items_updated']}");
            $this->line("Regole processate: {$stats['pricelist_items_processed']}");
            $this->line("Riferimenti locali non risolti: {$stats['invalid_references']}");
            $this->line("Errori: {$stats['errors']}");
            $this->line('Tempo esecuzione: ' . number_format($duration, 2) . 's');

            return self::SUCCESS;
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error('Import listini fallito: ' . $exception->getMessage());
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
