<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('listino_prodotto', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listino_id')
                ->constrained('Listini')
                ->cascadeOnDelete();
            $table->foreignId('product_id')
                ->constrained('Prodotti')
                ->cascadeOnDelete();

            $table->decimal('prezzo_lordo', 10, 2)->nullable();
            $table->decimal('sconto_percentuale', 5, 2)->nullable();
            $table->decimal('prezzo', 10, 2)->nullable();
            $table->decimal('iva_percentuale', 5, 2)->nullable();

            $table->timestamps();
            $table->unique(['listino_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listino_prodotto');
    }
};
