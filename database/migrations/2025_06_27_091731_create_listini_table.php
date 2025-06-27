<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('listini', function (Blueprint $table) {
            $table->id();
            $table->string('nome_listino');
            $table->date('valido_dal')->nullable();
            $table->date('valido_al')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listini');
    }
};
