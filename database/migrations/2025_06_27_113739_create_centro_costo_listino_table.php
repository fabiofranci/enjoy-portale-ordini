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
        Schema::create('centro_costo_listino', function (Blueprint $table) {
            $table->id();
            $table->foreignId('centro_costo_id')->constrained('centri_costo')->cascadeOnDelete();
            $table->foreignId('listino_id')->constrained('listini')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('centro_costo_listino');
    }
};
