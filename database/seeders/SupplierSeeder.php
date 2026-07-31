<?php

namespace Database\Seeders;

use App\Models\Fornitore;
use Illuminate\Database\Seeder;

class SupplierSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['code' => 'ICA', 'nome' => 'ICA'],
            ['code' => 'IGROUP', 'nome' => 'IGROUP'],
        ] as $supplier) {
            Fornitore::query()->updateOrCreate(
                ['code' => $supplier['code']],
                ['nome' => $supplier['nome']],
            );
        }
    }
}
