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
        Schema::create('clienti', function (Blueprint $table) {
            $table->id();
            $table->string('nome');
            $table->string('partita_iva')->unique();
            $table->string('email')->nullable();
            $table->string('telefono')->nullable();
            $table->string('indirizzo')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clienti');
    }
};
