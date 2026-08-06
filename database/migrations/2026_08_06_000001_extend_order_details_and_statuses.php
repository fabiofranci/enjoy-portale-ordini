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
        Schema::table('centri_costo', function (Blueprint $table): void {
            $table->text('indirizzo')->nullable()->after('descrizione');
        });

        Schema::table('ordini', function (Blueprint $table): void {
            $table->string('stato', 32)->default('nuovo')->change();
            $table->timestamp('data_ordine')->nullable()->after('stato');
            $table->string('inviato_da_nome')->nullable()->after('data_ordine');
            $table->string('inviato_da_email')->nullable()->after('inviato_da_nome');
            $table->string('riferimento_richiedente')->nullable()->after('riferimento_cliente');
            $table->string('priorita', 16)->default('standard')->after('riferimento_richiedente');
            $table->text('indirizzo_destinazione')->nullable()->after('priorita');
            $table->string('orari_consegna', 500)->nullable()->after('indirizzo_destinazione');
        });

        DB::table('ordini')
            ->where('stato', 'inviato')
            ->update(['stato' => 'nuovo']);

        DB::table('ordini')
            ->whereNull('data_ordine')
            ->update(['data_ordine' => DB::raw('created_at')]);

        DB::table('ordini')
            ->select(['id', 'user_id', 'centro_costo_id'])
            ->orderBy('id')
            ->chunkById(200, function ($orders): void {
                $users = DB::table('users')
                    ->whereIn('id', $orders->pluck('user_id')->filter())
                    ->get(['id', 'name', 'email'])
                    ->keyBy('id');
                $centers = DB::table('centri_costo')
                    ->whereIn('id', $orders->pluck('centro_costo_id')->filter())
                    ->get(['id', 'indirizzo'])
                    ->keyBy('id');

                foreach ($orders as $order) {
                    $user = $users->get($order->user_id);
                    $center = $centers->get($order->centro_costo_id);

                    DB::table('ordini')
                        ->where('id', $order->id)
                        ->update([
                            'inviato_da_nome' => $user?->name,
                            'inviato_da_email' => $user?->email,
                            'indirizzo_destinazione' => $center?->indirizzo,
                        ]);
                }
            });
    }

    public function down(): void
    {
        DB::table('ordini')->where('stato', 'nuovo')->update(['stato' => 'inviato']);
        DB::table('ordini')->where('stato', 'evaso')->update(['stato' => 'approvato']);

        Schema::table('ordini', function (Blueprint $table): void {
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
