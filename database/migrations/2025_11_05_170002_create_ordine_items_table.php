<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ordine_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('ordine_id')
                ->constrained('ordini')
                ->cascadeOnDelete();

            $table->foreignId('prodotto_id')   
                ->constrained('Prodotti')      
                ->cascadeOnDelete();

            $table->unsignedInteger('quantita')->default(1);

            // Prezzo congelato al momento di aggiunta (deriva dal listino attivo)
            $table->decimal('prezzo_unitario_lordo', 12, 4);
            $table->decimal('sconto_percentuale', 5, 2)->default(0);
            $table->decimal('iva_percentuale', 5, 2)->default(22);

            $table->decimal('totale_riga_netto', 12, 2)->default(0);
            $table->decimal('totale_riga_iva', 12, 2)->default(0);
            $table->decimal('totale_riga_lordo', 12, 2)->default(0);

            $table->timestamps();

            $table->unique(['ordine_id', 'prodotto_id']); // 👈 aggiornata chiave unica
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ordine_items');
    }
};
