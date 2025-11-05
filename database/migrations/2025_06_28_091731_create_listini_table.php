<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('Listini', function (Blueprint $table) {
            $table->id();
            $table->foreignId('centro_costo_id')->nullable()->constrained('centri_costo')->nullOnDelete();
            $table->foreignId('categoria_id')->nullable()->constrained('Categorie')->nullOnDelete();
            $table->decimal('sconto_percentuale', 5, 2)->default(0);
            $table->date('valido_dal');
            $table->date('valido_al')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('Listini');
    }
};
