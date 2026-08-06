<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ordini', function (Blueprint $table): void {
            $table->unsignedInteger('email_attempts')->default(0)->after('email_recipients');
            $table->timestamp('email_last_attempt_at')->nullable()->after('email_attempts');
            $table->text('email_last_error')->nullable()->after('email_last_attempt_at');
        });
    }

    public function down(): void
    {
        Schema::table('ordini', function (Blueprint $table): void {
            $table->dropColumn([
                'email_attempts',
                'email_last_attempt_at',
                'email_last_error',
            ]);
        });
    }
};
