<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('Prodotti', function (Blueprint $table) {
            $table->id();
            $table->string('nome');
            $table->string('codice')->unique();
            $table->foreignId('categoria_id')->constrained('Categorie')->cascadeOnDelete();
            $table->string('unita_misura')->nullable();
            $table->string('packaging')->nullable(); // 👈 senza after
            $table->decimal('prezzo_acquisto', 10, 2)->nullable(); // 👈 senza after
            $table->decimal('prezzo_listino', 10, 2)->nullable();
            $table->text('descrizione')->nullable();
            $table->string('immagine')->nullable();
            $table->string('pdf_sicurezza')->nullable();
            $table->boolean('disponibile')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('Prodotti');
    }
};
