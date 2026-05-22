<?php

use App\Services\Odoo\OdooClient;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('odoo:test-connection {--model= : Optional Odoo model to validate with a search_read request}', function () {
    try {
        $client = OdooClient::fromConfig();
        $uid = $client->authenticate();

        $this->info("✅ Odoo authenticated successfully. UID: {$uid}");

        $model = $this->option('model');
        if ($model) {
            $this->info("🔎 Testing model read access for {$model}...");
            $records = $client->searchRead($model, [], ['id'], ['limit' => 1]);
            $count = is_array($records) ? count($records) : 0;
            $this->info("✅ search_read succeeded. Records returned: {$count}");
        }

        return 0;
    } catch (\Throwable $exception) {
        $this->error('❌ Odoo connection failed: ' . $exception->getMessage());
        $this->error('See storage/logs/laravel.log for details.');

        return 1;
    }
});

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
