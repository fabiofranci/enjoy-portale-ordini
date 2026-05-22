<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Categorie', function (Blueprint $table): void {
            $table->bigInteger('odoo_id')->nullable();
            $table->timestamp('odoo_write_date')->nullable();
            $table->unique('odoo_id', 'categorie_odoo_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('Categorie', function (Blueprint $table): void {
            $table->dropUnique('categorie_odoo_id_unique');
            $table->dropColumn(['odoo_id', 'odoo_write_date']);
        });
    }
};
