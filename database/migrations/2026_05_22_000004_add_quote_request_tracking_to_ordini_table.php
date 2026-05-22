<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('ordini', function (Blueprint $table) {
            $table->bigInteger('odoo_lead_id')->nullable()->after('pdf_path');
            $table->timestamp('igroup_sent_at')->nullable()->after('odoo_lead_id');
            $table->timestamp('odoo_synced_at')->nullable()->after('igroup_sent_at');

            $table->index('odoo_lead_id', 'ordini_odoo_lead_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('ordini', function (Blueprint $table) {
            $table->dropIndex('ordini_odoo_lead_id_index');
            $table->dropColumn(['odoo_lead_id', 'igroup_sent_at', 'odoo_synced_at']);
        });
    }
};
