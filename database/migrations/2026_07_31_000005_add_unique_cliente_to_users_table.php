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
        $hasDuplicates = DB::table('users')
            ->whereNotNull('cliente_id')
            ->select('cliente_id')
            ->groupBy('cliente_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasDuplicates) {
            throw new RuntimeException(
                'Impossibile rendere univoco users.cliente_id: esistono piu account per lo stesso cliente.'
            );
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->unique('cliente_id', 'users_cliente_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique('users_cliente_id_unique');
        });
    }
};
