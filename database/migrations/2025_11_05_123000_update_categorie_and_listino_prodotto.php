<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Aggiunge codice alle categorie
        Schema::table('Categorie', function (Blueprint $table) {
            if (!Schema::hasColumn('Categorie', 'codice')) {
                $table->string('codice', 20)->nullable()->after('id');
            }
        });

        // Aggiunge prezzo_lordo e iva_percentuale alla pivot
        Schema::table('listino_prodotto', function (Blueprint $table) {
            if (!Schema::hasColumn('listino_prodotto', 'prezzo_lordo')) {
                $table->decimal('prezzo_lordo', 10, 2)->nullable()->after('prezzo');
            }
            if (!Schema::hasColumn('listino_prodotto', 'iva_percentuale')) {
                $table->decimal('iva_percentuale', 5, 2)->nullable()->after('prezzo_lordo');
            }
        });
    }

    public function down(): void
    {
        Schema::table('Categorie', function (Blueprint $table) {
            if (Schema::hasColumn('Categorie', 'codice')) {
                $table->dropColumn('codice');
            }
        });

        Schema::table('listino_prodotto', function (Blueprint $table) {
            if (Schema::hasColumn('listino_prodotto', 'prezzo_lordo')) {
                $table->dropColumn('prezzo_lordo');
            }
            if (Schema::hasColumn('listino_prodotto', 'iva_percentuale')) {
                $table->dropColumn('iva_percentuale');
            }
        });
    }
};
