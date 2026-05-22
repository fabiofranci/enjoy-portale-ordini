<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Odoo\OdooClient;
use App\Services\Odoo\Exceptions\OdooException;
use Illuminate\Console\Command;

class TestOdooConnection extends Command
{
    protected $signature = 'odoo:test-connection {--model= : Optional Odoo model to validate with a search_read request}';

    protected $description = 'Test the Odoo XML-RPC connection and authentication.';

    public function handle(): int
    {
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

            return self::SUCCESS;
        } catch (OdooException $exception) {
            $this->error('❌ Odoo connection failed: ' . $exception->getMessage());
            $this->error('See storage/logs/laravel.log for details.');

            return self::FAILURE;
        }
    }
}
