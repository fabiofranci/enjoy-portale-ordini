<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ordini', function (Blueprint $table): void {
            $table->foreignId('fornitore_id')
                ->nullable()
                ->after('centro_costo_id')
                ->constrained('fornitori')
                ->nullOnDelete();
            $table->string('cliente_nome')->nullable()->after('fornitore_id');
            $table->string('cliente_partita_iva', 32)->nullable()->after('cliente_nome');
            $table->string('centro_costo_nome')->nullable()->after('cliente_partita_iva');
            $table->string('fornitore_code', 32)->nullable()->after('centro_costo_nome');
            $table->string('email_stato', 20)->default('in_attesa')->after('pdf_path');
            $table->timestamp('email_sent_at')->nullable()->after('email_stato');
            $table->json('email_recipients')->nullable()->after('email_sent_at');
            $table->unique(
                ['user_id', 'riferimento_cliente'],
                'ordini_user_riferimento_unique'
            );

            $table->decimal('totale_netto', 12, 2)->nullable()->change();
            $table->decimal('iva_totale', 12, 2)->nullable()->change();
        });

        Schema::table('ordine_items', function (Blueprint $table): void {
            $table->foreignId('prodotto_id')->nullable()->change();
            $table->foreignId('listino_referenza_id')
                ->nullable()
                ->after('prodotto_id')
                ->constrained('listino_referenze')
                ->nullOnDelete();
            $table->string('fornitore_code', 32)->nullable()->after('listino_referenza_id');
            $table->string('supplier_code')->nullable()->after('fornitore_code');
            $table->string('customer_article_code')->nullable()->after('supplier_code');
            $table->string('descrizione')->nullable()->after('customer_article_code');
            $table->string('listino_nome')->nullable()->after('descrizione');
            $table->decimal('iva_percentuale', 5, 2)->nullable()->change();
            $table->decimal('totale_riga_netto', 12, 2)->nullable()->change();
            $table->decimal('totale_riga_iva', 12, 2)->nullable()->change();
            $table->unique(
                ['ordine_id', 'listino_referenza_id'],
                'ordine_items_ordine_listino_referenza_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('ordine_items', function (Blueprint $table): void {
            $table->dropUnique('ordine_items_ordine_listino_referenza_unique');
            $table->dropConstrainedForeignId('listino_referenza_id');
            $table->dropColumn([
                'fornitore_code',
                'supplier_code',
                'customer_article_code',
                'descrizione',
                'listino_nome',
            ]);
        });

        Schema::table('ordini', function (Blueprint $table): void {
            $table->dropUnique('ordini_user_riferimento_unique');
            $table->dropConstrainedForeignId('fornitore_id');
            $table->dropColumn([
                'cliente_nome',
                'cliente_partita_iva',
                'centro_costo_nome',
                'fornitore_code',
                'email_stato',
                'email_sent_at',
                'email_recipients',
            ]);

            $table->decimal('totale_netto', 12, 2)->default(0)->nullable(false)->change();
            $table->decimal('iva_totale', 12, 2)->default(0)->nullable(false)->change();
        });

        Schema::table('ordine_items', function (Blueprint $table): void {
            $table->foreignId('prodotto_id')->nullable(false)->change();
            $table->decimal('iva_percentuale', 5, 2)->default(22)->nullable(false)->change();
            $table->decimal('totale_riga_netto', 12, 2)->default(0)->nullable(false)->change();
            $table->decimal('totale_riga_iva', 12, 2)->default(0)->nullable(false)->change();
        });
    }
};
