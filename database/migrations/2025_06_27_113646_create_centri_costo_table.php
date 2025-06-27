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
        Schema::create('centri_costo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->constrained('clienti')->cascadeOnDelete();
            $table->string('nome');
            $table->string('descrizione')->nullable();
            $table->decimal('budget_annuale', 12, 2)->nullable();
            $table->decimal('budget_mensile', 12, 2)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('centri_costo');
    }
};
