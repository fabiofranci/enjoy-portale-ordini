<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ordini', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // cliente
            $table->foreignId('centro_costo_id')->nullable()->constrained('centri_costo')->nullOnDelete();
            $table->enum('stato', ['bozza','inviato','in_attesa_approvazione','rifiutato','approvato'])->default('bozza');
            $table->string('riferimento_cliente')->nullable();
            $table->text('note')->nullable();
            $table->boolean('extra_budget')->default(false);

            $table->decimal('totale_lordo', 12, 2)->default(0);
            $table->decimal('totale_netto', 12, 2)->default(0);
            $table->decimal('iva_totale', 12, 2)->default(0);

            $table->string('pdf_path')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ordini');
    }
};
