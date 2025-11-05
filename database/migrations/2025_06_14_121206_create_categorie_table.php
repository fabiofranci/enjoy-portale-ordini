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
        Schema::create('Categorie', function (Blueprint $table) {
            $table->id();
            $table->string('nome');
            $table->decimal('percentuale_ricarico', 5, 2)->default(0);
            $table->foreignId('categoria_padre_id')->nullable()->constrained('Categorie')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('categorie');
    }
};
