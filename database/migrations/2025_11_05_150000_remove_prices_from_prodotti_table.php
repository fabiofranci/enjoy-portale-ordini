<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('Prodotti', function (Blueprint $table) {
            foreach ([
                'prezzo_acquisto',
                'prezzo_listino',
                'prezzo_lordo',
                'sconto_percentuale',
                'iva_percentuale',
            ] as $col) {
                if (Schema::hasColumn('Prodotti', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('Prodotti', function (Blueprint $table) {
            // Se serve rollback, ripristiniamo i campi
            $table->decimal('prezzo_acquisto', 10, 2)->nullable();
            $table->decimal('prezzo_listino', 10, 2)->nullable();
            $table->decimal('prezzo_lordo', 10, 2)->nullable();
            $table->decimal('sconto_percentuale', 5, 2)->nullable();
            $table->decimal('iva_percentuale', 5, 2)->nullable();
        });
    }
};
