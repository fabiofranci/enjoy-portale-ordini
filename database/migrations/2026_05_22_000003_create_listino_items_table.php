<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ListinoItems', function (Blueprint $table): void {
            $table->id();
            $table->bigInteger('odoo_id')->nullable();
            $table->foreignId('listino_id')->nullable()->constrained('Listini')->nullOnDelete();
            $table->bigInteger('odoo_pricelist_id')->nullable();
            $table->timestamp('odoo_write_date')->nullable();
            $table->string('nome_regola')->nullable();
            $table->string('descrizione_prezzo')->nullable();
            $table->string('applied_on', 32);
            $table->string('display_applied_on', 32)->nullable();
            $table->decimal('min_quantity', 16, 4)->default(0);
            $table->timestamp('date_start')->nullable();
            $table->timestamp('date_end')->nullable();
            $table->foreignId('categoria_id')->nullable()->constrained('Categorie')->nullOnDelete();
            $table->bigInteger('odoo_categoria_id')->nullable();
            $table->foreignId('product_id')->nullable()->constrained('Prodotti')->nullOnDelete();
            $table->bigInteger('odoo_product_tmpl_id')->nullable();
            $table->bigInteger('odoo_product_variant_id')->nullable();
            $table->string('base', 32)->nullable();
            $table->foreignId('base_pricelist_id')->nullable()->constrained('Listini')->nullOnDelete();
            $table->bigInteger('odoo_base_pricelist_id')->nullable();
            $table->string('compute_price', 32)->nullable();
            $table->decimal('fixed_price', 16, 4)->nullable();
            $table->decimal('percent_price', 16, 4)->nullable();
            $table->decimal('price_discount', 16, 4)->nullable();
            $table->decimal('price_round', 16, 4)->nullable();
            $table->decimal('price_surcharge', 16, 4)->nullable();
            $table->decimal('price_markup', 16, 4)->nullable();
            $table->decimal('price_min_margin', 16, 4)->nullable();
            $table->decimal('price_max_margin', 16, 4)->nullable();
            $table->timestamps();

            $table->unique('odoo_id', 'listino_items_odoo_id_unique');
            $table->index(['listino_id', 'applied_on'], 'listino_items_listino_applied_on_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ListinoItems');
    }
};
