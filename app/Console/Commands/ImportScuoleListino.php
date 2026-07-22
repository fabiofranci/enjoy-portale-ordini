<?php

namespace App\Console\Commands;

use App\Services\Imports\ScuoleListinoImportService;
use Illuminate\Console\Command;
use Throwable;

class ImportScuoleListino extends Command
{
    protected $signature = 'scuole:import-listino
        {file : Percorso del file CSV, XLS o XLSX}
        {--dry-run : Analizza il file senza scrivere nel database}
        {--execute : Esegue l’import reale in transazione}
        {--listino=Scuole : Nome del listino custom}
        {--valid-from= : Data inizio validita prevista}
        {--valid-to= : Data fine validita prevista}';

    protected $description = 'Analizza o importa il listino custom Scuole senza integrazione Odoo.';

    public function handle(ScuoleListinoImportService $service): int
    {
        if ($this->option('dry-run') && $this->option('execute')) {
            $this->error('Usa una sola opzione tra --dry-run e --execute.');

            return self::FAILURE;
        }

        if (!$this->option('dry-run') && !$this->option('execute')) {
            $this->error('Aggiungi --dry-run per analizzare oppure --execute per importare realmente.');

            return self::FAILURE;
        }

        $options = [
            'listino' => (string) $this->option('listino'),
            'valid_from' => $this->option('valid-from'),
            'valid_to' => $this->option('valid-to'),
        ];

        try {
            $result = $this->option('dry-run')
                ? $service->dryRun((string) $this->argument('file'), $options)
                : $service->import((string) $this->argument('file'), $options);
        } catch (Throwable $exception) {
            $this->error('Comando listino Scuole fallito: ' . $exception->getMessage());

            return self::FAILURE;
        }

        $this->renderSummary($result);
        $this->renderDetails($result);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function renderSummary(array $result): void
    {
        if (($result['options']['execute'] ?? false) === true) {
            $this->info('Import listino Scuole completato.');
        } else {
            $this->info('Dry-run listino Scuole completato. Nessuna scrittura eseguita.');
        }

        $this->line('File: ' . ($result['file']['path'] ?? 'n/d'));
        $this->line('Listino: ' . ($result['options']['listino'] ?? 'Scuole'));

        if (($result['options']['valid_from'] ?? null) !== null) {
            $this->line('Valido dal: ' . $result['options']['valid_from']);
        }

        if (($result['options']['valid_to'] ?? null) !== null) {
            $this->line('Valido al: ' . $result['options']['valid_to']);
        }

        $this->newLine();

        $rows = [];

        foreach ($result['summary'] as $label => $value) {
            $rows[] = [$label, (string) $value];
        }

        $this->table(['Voce', 'Totale'], $rows);

        if (($result['write_summary'] ?? []) !== []) {
            $this->newLine();
            $this->table(
                ['Scrittura', 'Totale'],
                array_map(
                    static fn (string $label, mixed $value): array => [$label, (string) $value],
                    array_keys($result['write_summary']),
                    $result['write_summary']
                )
            );
        }
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function renderDetails(array $result): void
    {
        $this->renderAnomalies('Errori', $result['errors'] ?? []);
        $this->renderConflicts($result['duplicates']['conflicting'] ?? []);
        $this->renderAnomalies('Prezzi non validi', $result['prices']['invalid'] ?? []);
        $this->renderAnomalies('Packaging incompleti', $result['packaging']['incomplete'] ?? []);
        $this->renderAnomalies('Packaging conflittuali', $result['packaging']['conflicting'] ?? []);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function renderAnomalies(string $title, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $this->newLine();
        $this->warn($title);
        $this->table(
            ['Riga', 'SKU', 'Descrizione', 'Categoria', 'Valore', 'Motivo'],
            array_map(static fn (array $row): array => [
                (string) ($row['line'] ?? ''),
                (string) ($row['sku'] ?? ''),
                mb_strimwidth((string) ($row['description'] ?? ''), 0, 60, '...'),
                mb_strimwidth((string) ($row['category'] ?? ''), 0, 30, '...'),
                mb_strimwidth((string) ($row['source_value'] ?? ''), 0, 30, '...'),
                (string) ($row['reason'] ?? ''),
            ], array_slice($rows, 0, 25))
        );

        if (count($rows) > 25) {
            $this->line(sprintf('...altri %d elementi non mostrati.', count($rows) - 25));
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $conflicts
     */
    private function renderConflicts(array $conflicts): void
    {
        if ($conflicts === []) {
            return;
        }

        $this->newLine();
        $this->warn('Conflitti SKU');
        $this->table(
            ['SKU', 'Righe', 'Motivi', 'Prima descrizione', 'Descrizione conflitto'],
            array_map(static fn (array $conflict): array => [
                (string) ($conflict['sku'] ?? ''),
                implode(',', $conflict['lines'] ?? []),
                implode(',', $conflict['reasons'] ?? []),
                mb_strimwidth((string) ($conflict['first']['description'] ?? ''), 0, 50, '...'),
                mb_strimwidth((string) ($conflict['current']['description'] ?? ''), 0, 50, '...'),
            ], array_slice($conflicts, 0, 25))
        );

        if (count($conflicts) > 25) {
            $this->line(sprintf('...altri %d conflitti non mostrati.', count($conflicts) - 25));
        }
    }
}
