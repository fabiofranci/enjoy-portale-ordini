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
            $table->text('descrizione')->nullable();
            $table->string('immagine')->nullable();
            $table->string('pdf_sicurezza')->nullable();
            $table->boolean('disponibile')->default(true); // ✅ nuovo campo
            $table->timestamps();
            $table->softDeletes(); // ✅ soft delete
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('Prodotti');
    }
};
