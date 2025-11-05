<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('Listini', function (Blueprint $table) {
            if (!Schema::hasColumn('Listini', 'fornitore_id')) {
                $table->foreignId('fornitore_id')
                    ->nullable()
                    ->after('centro_costo_id')
                    ->constrained('fornitori')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('Listini', function (Blueprint $table) {
            if (Schema::hasColumn('Listini', 'fornitore_id')) {
                $table->dropForeign(['fornitore_id']);
                $table->dropColumn('fornitore_id');
            }
        });
    }
};
