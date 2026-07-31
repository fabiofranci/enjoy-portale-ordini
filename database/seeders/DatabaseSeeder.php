<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(SupplierSeeder::class);

        if (app()->environment('production')) {
            return;
        }

        // These seeders contain local/demo identities and fixed sample data.
        $this->call([
            RoleSeeder::class,
            AdminUserSeeder::class,
            CategorieSeeder::class,
            ClienteSeeder::class,
        ]);
    }
}
