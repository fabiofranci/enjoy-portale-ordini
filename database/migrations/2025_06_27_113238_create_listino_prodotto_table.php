<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('listino_prodotto', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listino_id')->constrained('listini')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('Prodotti')->cascadeOnDelete();

            $table->decimal('prezzo_listino', 10, 2);
            $table->decimal('prezzo_base', 10, 2)->nullable();
            $table->decimal('prezzo_acquisto', 10, 2)->nullable();
            $table->decimal('sconto', 5, 2)->nullable();
            $table->decimal('coeff_provvigione', 5, 2)->nullable();
            $table->decimal('provvigione_agente', 5, 2)->nullable();
            $table->decimal('maggiorazione_carta', 5, 2)->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('listino_prodotto');
    }
};
