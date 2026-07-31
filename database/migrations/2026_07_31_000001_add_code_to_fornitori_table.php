<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fornitori', function (Blueprint $table): void {
            $table->string('code', 64)->nullable()->after('id');
        });

        $this->backfillLegacySupplierCodes();

        Schema::table('fornitori', function (Blueprint $table): void {
            $table->string('code', 64)->nullable(false)->change();
            $table->unique('code', 'fornitori_code_unique');
        });
    }

    public function down(): void
    {
        Schema::table('fornitori', function (Blueprint $table): void {
            $table->dropUnique('fornitori_code_unique');
            $table->dropColumn('code');
        });
    }

    private function backfillLegacySupplierCodes(): void
    {
        $usedCodes = [];

        DB::table('fornitori')
            ->select(['id', 'nome'])
            ->orderBy('id')
            ->get()
            ->each(function (object $supplier) use (&$usedCodes): void {
                $baseCode = Str::of((string) $supplier->nome)
                    ->ascii()
                    ->upper()
                    ->replaceMatches('/[^A-Z0-9]+/', '_')
                    ->trim('_')
                    ->limit(48, '')
                    ->toString();
                $baseCode = $baseCode !== '' ? $baseCode : 'FORNITORE';
                $code = $baseCode;
                $suffix = 2;

                while (isset($usedCodes[$code])) {
                    $code = $baseCode.'_'.$suffix;
                    $suffix++;
                }

                DB::table('fornitori')
                    ->where('id', $supplier->id)
                    ->update(['code' => $code]);

                $usedCodes[$code] = true;
            });
    }
};
