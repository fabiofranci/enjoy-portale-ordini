<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_packagings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('product_id')
                ->constrained('Prodotti')
                ->cascadeOnDelete();

            $table->string('from_unit'); // es. CF
            $table->string('to_unit');   // es. NR

            $table->decimal('multiplier', 10, 4);

            $table->timestamps();

            $table->unique(['product_id', 'from_unit', 'to_unit'], 'product_packaging_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_packagings');
    }
};
