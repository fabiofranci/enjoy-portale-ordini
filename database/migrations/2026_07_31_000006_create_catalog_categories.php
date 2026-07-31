<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categorie_catalogo', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fornitore_id')->constrained('fornitori')->restrictOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('categorie_catalogo')->nullOnDelete();
            $table->string('codice')->nullable();
            $table->string('nome');
            $table->string('slug');
            $table->boolean('attiva')->default(true);
            $table->timestamps();

            $table->unique(['fornitore_id', 'codice'], 'categorie_catalogo_supplier_code_unique');
            $table->unique(['fornitore_id', 'slug'], 'categorie_catalogo_supplier_slug_unique');
        });

        Schema::create('referenza_fornitore_categoria', function (Blueprint $table): void {
            $table->foreignId('referenza_fornitore_id')
                ->constrained('referenze_fornitore')
                ->cascadeOnDelete();
            $table->foreignId('categoria_catalogo_id')
                ->constrained('categorie_catalogo')
                ->cascadeOnDelete();
            $table->timestamps();

            $table->primary(
                ['referenza_fornitore_id', 'categoria_catalogo_id'],
                'referenza_categoria_primary'
            );
        });

        DB::table('referenze_fornitore')
            ->whereNotNull('categoria')
            ->where('categoria', '<>', '')
            ->select(['id', 'fornitore_id', 'categoria'])
            ->orderBy('id')
            ->each(function (object $reference): void {
                $name = trim((string) $reference->categoria);
                $slug = Str::slug($name);

                if ($slug === '') {
                    $slug = 'categoria-'.hash('sha256', mb_strtolower($name));
                }

                $categoryId = DB::table('categorie_catalogo')
                    ->where('fornitore_id', $reference->fornitore_id)
                    ->where('slug', $slug)
                    ->value('id');

                if ($categoryId === null) {
                    $categoryId = DB::table('categorie_catalogo')->insertGetId([
                        'fornitore_id' => $reference->fornitore_id,
                        'parent_id' => null,
                        'codice' => null,
                        'nome' => $name,
                        'slug' => $slug,
                        'attiva' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                DB::table('referenza_fornitore_categoria')->insertOrIgnore([
                    'referenza_fornitore_id' => $reference->id,
                    'categoria_catalogo_id' => $categoryId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('referenza_fornitore_categoria');
        Schema::dropIfExists('categorie_catalogo');
    }
};
