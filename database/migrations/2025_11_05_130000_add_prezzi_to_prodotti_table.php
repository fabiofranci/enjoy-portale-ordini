<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('Prodotti', function (Blueprint $table) {
            if (!Schema::hasColumn('Prodotti', 'prezzo_lordo')) {
                $table->decimal('prezzo_lordo', 10, 2)->nullable()->after('prezzo_listino');
            }
            if (!Schema::hasColumn('Prodotti', 'sconto_percentuale')) {
                $table->decimal('sconto_percentuale', 5, 2)->nullable()->after('prezzo_lordo');
            }
            if (!Schema::hasColumn('Prodotti', 'iva_percentuale')) {
                $table->decimal('iva_percentuale', 5, 2)->nullable()->after('sconto_percentuale');
            }
        });
    }

    public function down(): void
    {
        Schema::table('Prodotti', function (Blueprint $table) {
            $table->dropColumn(['prezzo_lordo', 'sconto_percentuale', 'iva_percentuale']);
        });
    }
};
