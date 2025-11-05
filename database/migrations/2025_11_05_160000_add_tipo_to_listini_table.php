<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('Listini', function (Blueprint $table) {
            if (!Schema::hasColumn('Listini', 'tipo')) {
                $table->enum('tipo', ['acquisto', 'vendita'])
                    ->default('acquisto')
                    ->after('nome_listino');
            }
        });
    }

    public function down(): void
    {
        Schema::table('Listini', function (Blueprint $table) {
            if (Schema::hasColumn('Listini', 'tipo')) {
                $table->dropColumn('tipo');
            }
        });
    }
};
