<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ordini', function (Blueprint $table): void {
            $table->string('xlsx_path')->nullable()->after('pdf_path');
        });

        DB::table('ordini')
            ->whereNotNull('pdf_path')
            ->select(['id', 'pdf_path'])
            ->orderBy('id')
            ->chunkById(100, function ($orders): void {
                foreach ($orders as $order) {
                    $path = trim((string) $order->pdf_path);

                    if ($path === '' || ! Storage::disk('public')->exists($path)) {
                        continue;
                    }

                    $content = Storage::disk('public')->get($path);

                    if (Storage::disk('local')->put($path, $content)) {
                        Storage::disk('public')->delete($path);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('ordini', function (Blueprint $table): void {
            $table->dropColumn('xlsx_path');
        });
    }
};
