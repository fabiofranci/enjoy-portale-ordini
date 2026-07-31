<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('referenze_fornitore', function (Blueprint $table): void {
            $table->boolean('attivo')->default(true)->after('sales_unit');
        });

        Schema::table('listino_referenze', function (Blueprint $table): void {
            $table->boolean('attivo')->default(true)->after('prezzo_cartone');
        });
    }

    public function down(): void
    {
        Schema::table('listino_referenze', function (Blueprint $table): void {
            $table->dropColumn('attivo');
        });

        Schema::table('referenze_fornitore', function (Blueprint $table): void {
            $table->dropColumn('attivo');
        });
    }
};
