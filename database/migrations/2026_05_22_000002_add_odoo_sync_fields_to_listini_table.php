<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Listini', function (Blueprint $table): void {
            $table->bigInteger('odoo_id')->nullable();
            $table->timestamp('odoo_write_date')->nullable();
            $table->boolean('attivo')->default(true);
            $table->integer('sequenza')->nullable();
            $table->bigInteger('odoo_currency_id')->nullable();
            $table->string('odoo_currency_name')->nullable();
            $table->bigInteger('odoo_company_id')->nullable();
            $table->string('odoo_company_name')->nullable();
            $table->unique('odoo_id', 'listini_odoo_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('Listini', function (Blueprint $table): void {
            $table->dropUnique('listini_odoo_id_unique');
            $table->dropColumn([
                'odoo_id',
                'odoo_write_date',
                'attivo',
                'sequenza',
                'odoo_currency_id',
                'odoo_currency_name',
                'odoo_company_id',
                'odoo_company_name',
            ]);
        });
    }
};
