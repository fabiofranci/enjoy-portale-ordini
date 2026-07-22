<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('centro_costo_listino')) {
            Schema::create('centro_costo_listino', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('centro_costo_id')->constrained('centri_costo')->cascadeOnDelete();
                $table->foreignId('listino_id')->constrained('Listini')->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['centro_costo_id', 'listino_id'], 'centro_costo_listino_unique');
            });

            return;
        }

        $this->ensureExistingTableColumns();
        $this->ensureUniqueIndex();
        $this->ensureForeignKeys();
    }

    public function down(): void
    {
        if (!Schema::hasTable('centro_costo_listino')) {
            return;
        }

        if ($this->hasMigrationCreatedShape() && DB::table('centro_costo_listino')->count() === 0) {
            Schema::dropIfExists('centro_costo_listino');
        }
    }

    private function ensureExistingTableColumns(): void
    {
        if (!Schema::hasColumn('centro_costo_listino', 'id') && !$this->primaryKeyExists()) {
            Schema::table('centro_costo_listino', function (Blueprint $table): void {
                $table->id()->first();
            });
        }

        Schema::table('centro_costo_listino', function (Blueprint $table): void {
            if (!Schema::hasColumn('centro_costo_listino', 'centro_costo_id')) {
                $table->foreignId('centro_costo_id')->nullable()->after('id');
            }

            if (!Schema::hasColumn('centro_costo_listino', 'listino_id')) {
                $table->foreignId('listino_id')->nullable()->after('centro_costo_id');
            }

            if (!Schema::hasColumn('centro_costo_listino', 'created_at')) {
                $table->timestamp('created_at')->nullable()->after('listino_id');
            }

            if (!Schema::hasColumn('centro_costo_listino', 'updated_at')) {
                $table->timestamp('updated_at')->nullable()->after('created_at');
            }
        });
    }

    private function ensureUniqueIndex(): void
    {
        if (!Schema::hasColumn('centro_costo_listino', 'centro_costo_id')
            || !Schema::hasColumn('centro_costo_listino', 'listino_id')
            || $this->indexExists('centro_costo_listino_unique')) {
            return;
        }

        $duplicatesExist = DB::table('centro_costo_listino')
            ->select('centro_costo_id', 'listino_id')
            ->whereNotNull('centro_costo_id')
            ->whereNotNull('listino_id')
            ->groupBy('centro_costo_id', 'listino_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($duplicatesExist) {
            throw new RuntimeException(
                'Impossibile aggiungere il vincolo unique a centro_costo_listino: esistono associazioni duplicate.'
            );
        }

        Schema::table('centro_costo_listino', function (Blueprint $table): void {
            $table->unique(['centro_costo_id', 'listino_id'], 'centro_costo_listino_unique');
        });
    }

    private function ensureForeignKeys(): void
    {
        if (!in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        if (Schema::hasColumn('centro_costo_listino', 'centro_costo_id')
            && !$this->foreignKeyExists('centro_costo_listino_centro_costo_id_foreign')) {
            Schema::table('centro_costo_listino', function (Blueprint $table): void {
                $table->foreign('centro_costo_id', 'centro_costo_listino_centro_costo_id_foreign')
                    ->references('id')
                    ->on('centri_costo')
                    ->cascadeOnDelete();
            });
        }

        if (Schema::hasColumn('centro_costo_listino', 'listino_id')
            && !$this->foreignKeyExists('centro_costo_listino_listino_id_foreign')) {
            Schema::table('centro_costo_listino', function (Blueprint $table): void {
                $table->foreign('listino_id', 'centro_costo_listino_listino_id_foreign')
                    ->references('id')
                    ->on('Listini')
                    ->cascadeOnDelete();
            });
        }
    }

    private function hasMigrationCreatedShape(): bool
    {
        return Schema::hasColumn('centro_costo_listino', 'id')
            && Schema::hasColumn('centro_costo_listino', 'centro_costo_id')
            && Schema::hasColumn('centro_costo_listino', 'listino_id')
            && Schema::hasColumn('centro_costo_listino', 'created_at')
            && Schema::hasColumn('centro_costo_listino', 'updated_at');
    }

    private function primaryKeyExists(): bool
    {
        $driver = DB::getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            return DB::table('information_schema.table_constraints')
                ->where('table_schema', DB::getDatabaseName())
                ->where('table_name', 'centro_costo_listino')
                ->where('constraint_type', 'PRIMARY KEY')
                ->exists();
        }

        if ($driver === 'sqlite') {
            foreach (DB::select('PRAGMA table_info("centro_costo_listino")') as $column) {
                if ((int) $column->pk > 0) {
                    return true;
                }
            }
        }

        return false;
    }

    private function indexExists(string $indexName): bool
    {
        $driver = DB::getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            return DB::table('information_schema.statistics')
                ->where('table_schema', DB::getDatabaseName())
                ->where('table_name', 'centro_costo_listino')
                ->where('index_name', $indexName)
                ->exists();
        }

        if ($driver === 'sqlite') {
            foreach (DB::select('PRAGMA index_list("centro_costo_listino")') as $index) {
                if ($index->name === $indexName) {
                    return true;
                }
            }
        }

        return false;
    }

    private function foreignKeyExists(string $constraintName): bool
    {
        return DB::table('information_schema.key_column_usage')
            ->where('constraint_schema', DB::getDatabaseName())
            ->where('table_name', 'centro_costo_listino')
            ->where('constraint_name', $constraintName)
            ->exists();
    }
};
