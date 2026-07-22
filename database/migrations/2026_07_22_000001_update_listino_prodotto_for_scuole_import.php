<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('listino_prodotto')) {
            return;
        }

        Schema::table('listino_prodotto', function (Blueprint $table): void {
            if (!Schema::hasColumn('listino_prodotto', 'ordinabile')) {
                $table->boolean('ordinabile')->default(true)->after('iva_percentuale');
            }

            if (!Schema::hasColumn('listino_prodotto', 'motivo_non_ordinabile')) {
                $table->string('motivo_non_ordinabile')->nullable()->after('ordinabile');
            }

            if (!Schema::hasColumn('listino_prodotto', 'prezzo_sorgente')) {
                $table->decimal('prezzo_sorgente', 12, 5)->nullable()->after('motivo_non_ordinabile');
            }

            if (!Schema::hasColumn('listino_prodotto', 'unita_prezzo_sorgente')) {
                $table->string('unita_prezzo_sorgente')->nullable()->after('prezzo_sorgente');
            }
        });

        $this->modifyDecimalPrecision('prezzo_lordo', 12, 5);
        $this->modifyDecimalPrecision('prezzo', 12, 5);
    }

    public function down(): void
    {
        if (!Schema::hasTable('listino_prodotto')) {
            return;
        }

        Schema::table('listino_prodotto', function (Blueprint $table): void {
            foreach ([
                'unita_prezzo_sorgente',
                'prezzo_sorgente',
                'motivo_non_ordinabile',
                'ordinabile',
            ] as $column) {
                if (Schema::hasColumn('listino_prodotto', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        $this->modifyDecimalPrecision('prezzo_lordo', 10, 2);
        $this->modifyDecimalPrecision('prezzo', 10, 2);
    }

    private function modifyDecimalPrecision(string $column, int $precision, int $scale): void
    {
        if (!Schema::hasColumn('listino_prodotto', $column)) {
            return;
        }

        if (!in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::statement(sprintf(
            'ALTER TABLE `listino_prodotto` MODIFY `%s` DECIMAL(%d,%d) NULL',
            $column,
            $precision,
            $scale
        ));
    }
};
