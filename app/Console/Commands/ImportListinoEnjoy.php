<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Product;
use App\Models\Categoria;
use App\Models\Listino;
use Illuminate\Support\Facades\DB;

class ImportListinoEnjoy extends Command
{
    protected $signature = 'enjoy:import-listino {file}';
    protected $description = 'Importa un file CSV di listino prodotti (prezzi, sconti, IVA, ecc.)';

    public function handle()
    {
        $path = $this->argument('file');
        if (!file_exists($path)) {
            $this->error("File non trovato: $path");
            return Command::FAILURE;
        }

        $listinoName = 'Listino Generale Enjoy ' . now()->format('M Y');

        $listino = Listino::firstOrCreate(
            ['nome_listino' => $listinoName],
            [
                'centro_costo_id' => null,
                'categoria_id' => null,
                'sconto_percentuale' => 0,
                'valido_dal' => now()->startOfMonth(),
                'valido_al' => null,
            ]
        );

        $this->info("Usato listino ID {$listino->id} ({$listinoName})");

        $handle = fopen($path, 'r');
        fgetcsv($handle, 0, ','); // header

        $countNew = $countUpd = 0;

        DB::beginTransaction();

        while (($row = fgetcsv($handle, 0, ',')) !== false) {
            [$catField, $codice, $descrizione, $prezzoLordo, $iva, $sconto, $prezzoNetto, $um] = $row;

            if (empty($codice) || empty($descrizione)) continue;

            // Estrai codice e nome categoria
            preg_match('/^([0-9\.]+)/', $catField, $match);
            $catCode = $match[1] ?? null;
            $catNome = trim(preg_replace('/^[0-9\.\s]+[0-9\.\s]+/', '', $catField));
            $catNome = preg_replace('/\s+/', ' ', $catNome);

            $categoria = Categoria::firstOrCreate(
                ['nome' => $catNome],
                ['codice' => $catCode, 'percentuale_ricarico' => 0, 'categoria_padre_id' => null]
            );

            // Normalizza numeri
            $prezzoLordo = self::parseNumber($prezzoLordo);
            $prezzoNetto = self::parseNumber($prezzoNetto ?? $prezzoLordo);
            $iva = self::parseNumber($iva);
            $sconto = self::parseNumber($sconto);

            // Trova o crea prodotto
            $product = Product::firstOrCreate(
                ['codice' => trim($codice)],
                [
                    'nome' => trim($descrizione),
                    'categoria_id' => $categoria->id,
                    'unita_misura' => trim($um),
                    'disponibile' => true,
                ]
            );

            // Collega o aggiorna pivot
            $listino->products()->syncWithoutDetaching([
                $product->id => [
                    'prezzo_lordo' => $prezzoLordo,
                    'sconto_percentuale' => $sconto,
                    'prezzo' => $prezzoNetto,
                    'iva_percentuale' => $iva,
                ],
            ]);

            $countNew++;
        }

        DB::commit();
        fclose($handle);

        $this->info("✅ Import completato: {$countNew} prodotti elaborati.");

        return Command::SUCCESS;
    }

    protected static function parseNumber(?string $val): ?float
    {
        if (!$val) return null;
        $val = str_replace(['.', ' '], ['', ''], $val);
        $val = str_replace(',', '.', $val);
        return (float)$val;
    }
}
