<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('ordine_items', 'unita')) {
            Schema::table('ordine_items', function (Blueprint $table) {
                $table->string('unita', 32)->default('NR')->after('prodotto_id');
            });
        }

        DB::table('ordine_items')
            ->whereNull('unita')
            ->update(['unita' => 'NR']);

        if (!$this->indexExists('ordine_items', 'ordine_items_ordine_prodotto_unita_unique')) {
            Schema::table('ordine_items', function (Blueprint $table) {
                $table->unique(['ordine_id', 'prodotto_id', 'unita'], 'ordine_items_ordine_prodotto_unita_unique');
            });
        }

        if ($this->indexExists('ordine_items', 'ordine_items_ordine_id_prodotto_id_unique')) {
            Schema::table('ordine_items', function (Blueprint $table) {
                $table->dropUnique('ordine_items_ordine_id_prodotto_id_unique');
            });
        }
    }

    public function down(): void
    {
        if (!$this->indexExists('ordine_items', 'ordine_items_ordine_id_prodotto_id_unique')) {
            Schema::table('ordine_items', function (Blueprint $table) {
                $table->unique(['ordine_id', 'prodotto_id'], 'ordine_items_ordine_id_prodotto_id_unique');
            });
        }

        if ($this->indexExists('ordine_items', 'ordine_items_ordine_prodotto_unita_unique')) {
            Schema::table('ordine_items', function (Blueprint $table) {
                $table->dropUnique('ordine_items_ordine_prodotto_unita_unique');
            });
        }

        if (Schema::hasColumn('ordine_items', 'unita')) {
            Schema::table('ordine_items', function (Blueprint $table) {
                $table->dropColumn('unita');
            });
        }
    }

    private function indexExists(string $tableName, string $indexName): bool
    {
        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $tableName)
            ->where('index_name', $indexName)
            ->exists();
    }
};
