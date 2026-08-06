<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('centri_costo', function (Blueprint $table): void {
            $table->text('indirizzo')->nullable()->after('descrizione');
        });

        Schema::table('ordini', function (Blueprint $table): void {
            $table->string('stato', 32)->default('nuovo')->change();
            $table->timestamp('data_ordine')->nullable()->index()->after('stato');
            $table->string('inviato_da_nome')->nullable()->after('data_ordine');
            $table->string('inviato_da_email')->nullable()->after('inviato_da_nome');
            $table->string('riferimento_richiedente')->nullable()->after('riferimento_cliente');
            $table->string('priorita', 16)->default('standard')->after('riferimento_richiedente');
            $table->text('indirizzo_destinazione')->nullable()->after('priorita');
            $table->string('orari_consegna', 500)->nullable()->after('indirizzo_destinazione');
        });

    }

    public function down(): void
    {
        Schema::table('ordini', function (Blueprint $table): void {
            $table->dropIndex(['data_ordine']);
            $table->dropColumn([
                'data_ordine',
                'inviato_da_nome',
                'inviato_da_email',
                'riferimento_richiedente',
                'priorita',
                'indirizzo_destinazione',
                'orari_consegna',
            ]);
            $table->enum('stato', [
                'bozza',
                'inviato',
                'in_attesa_approvazione',
                'rifiutato',
                'approvato',
            ])->default('bozza')->change();
        });

        Schema::table('centri_costo', function (Blueprint $table): void {
            $table->dropColumn('indirizzo');
        });
    }
};
