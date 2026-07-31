<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Listini', function (Blueprint $table): void {
            $table->text('descrizione')->nullable()->after('nome_listino');
            $table->date('valido_dal')->nullable()->change();
        });

        Schema::create('referenze_fornitore', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fornitore_id')->constrained('fornitori')->restrictOnDelete();
            $table->string('supplier_code');
            $table->string('customer_article_code')->nullable();
            $table->string('external_source_id')->nullable();
            $table->string('descrizione');
            $table->text('descrizione_estesa')->nullable();
            $table->string('categoria')->nullable();
            $table->string('sales_unit', 32)->nullable();
            $table->boolean('ordinabile')->default(true);
            $table->string('motivo_non_ordinabile')->nullable();
            $table->string('immagine_path')->nullable();
            $table->string('immagine_hash', 64)->nullable();
            $table->string('source_profile')->nullable();
            $table->string('source_hash', 64);
            $table->json('source_metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['fornitore_id', 'supplier_code'],
                'referenze_fornitore_supplier_code_unique'
            );
            $table->index(
                ['fornitore_id', 'customer_article_code'],
                'referenze_fornitore_customer_code_index'
            );
            $table->index(
                ['fornitore_id', 'external_source_id'],
                'referenze_fornitore_external_id_index'
            );
        });

        Schema::create('referenza_packagings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('referenza_fornitore_id')
                ->constrained('referenze_fornitore')
                ->cascadeOnDelete();
            $table->string('unita_contenitore', 32);
            $table->string('unita_contenuta', 32);
            $table->decimal('quantita', 12, 5);
            $table->unsignedSmallInteger('livello')->nullable();
            $table->string('origine_campo')->nullable();
            $table->string('origine_valore')->nullable();
            $table->boolean('obbligatorio')->default(false);
            $table->timestamps();

            $table->unique(
                ['referenza_fornitore_id', 'unita_contenitore', 'unita_contenuta'],
                'referenza_packagings_units_unique'
            );
        });

        Schema::create('listino_referenze', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('listino_id')->constrained('Listini')->cascadeOnDelete();
            $table->foreignId('referenza_fornitore_id')
                ->constrained('referenze_fornitore')
                ->restrictOnDelete();
            $table->decimal('prezzo', 12, 5)->nullable();
            $table->decimal('prezzo_sorgente', 12, 5)->nullable();
            $table->string('price_unit', 32)->nullable();
            $table->decimal('prezzo_lordo', 12, 5)->nullable();
            $table->decimal('sconto_percentuale', 8, 5)->nullable();
            $table->decimal('iva_percentuale', 8, 5)->nullable();
            $table->decimal('prezzo_cartone', 12, 5)->nullable();
            $table->boolean('ordinabile')->default(true);
            $table->string('motivo_non_ordinabile')->nullable();
            $table->boolean('modificato_manualmente')->default(false);
            $table->timestamps();

            $table->unique(
                ['listino_id', 'referenza_fornitore_id'],
                'listino_referenze_listino_referenza_unique'
            );
        });

        Schema::create('import_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fornitore_id')->constrained('fornitori')->restrictOnDelete();
            $table->foreignId('listino_id')->nullable()->constrained('Listini')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('nome_file_originale');
            $table->string('file_hash', 64);
            $table->string('profilo')->nullable();
            $table->string('stato', 32);
            $table->unsignedInteger('righe_lette')->default(0);
            $table->unsignedInteger('referenze_create')->default(0);
            $table->unsignedInteger('referenze_aggiornate')->default(0);
            $table->unsignedInteger('prezzi_creati')->default(0);
            $table->unsignedInteger('prezzi_aggiornati')->default(0);
            $table->unsignedInteger('righe_ignorate')->default(0);
            $table->json('warnings')->nullable();
            $table->json('errori')->nullable();
            $table->json('riepilogo')->nullable();
            $table->timestamp('iniziato_il')->nullable();
            $table->timestamp('completato_il')->nullable();
            $table->timestamps();

            $table->index(['fornitore_id', 'file_hash'], 'import_batches_supplier_hash_index');
            $table->index(['stato', 'created_at'], 'import_batches_status_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_batches');
        Schema::dropIfExists('listino_referenze');
        Schema::dropIfExists('referenza_packagings');
        Schema::dropIfExists('referenze_fornitore');

        DB::table('Listini')
            ->whereNull('valido_dal')
            ->update(['valido_dal' => now()->toDateString()]);

        Schema::table('Listini', function (Blueprint $table): void {
            $table->date('valido_dal')->nullable(false)->change();
            $table->dropColumn('descrizione');
        });
    }
};
