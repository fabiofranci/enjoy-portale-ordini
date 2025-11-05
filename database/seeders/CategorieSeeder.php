<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class CategorieSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('Categorie')->insert([
            [
                'id' => 1,
                'nome' => 'ADDITIVI E SAPONI',
                'percentuale_ricarico' => 0.00,
                'categoria_padre_id' => null,
                'created_at' => '2025-07-07 11:40:45',
                'updated_at' => '2025-07-07 11:40:45',
            ],
            [
                'id' => 2,
                'nome' => 'LAVANDERIA MANUALE',
                'percentuale_ricarico' => 0.00,
                'categoria_padre_id' => null,
                'created_at' => '2025-07-07 11:41:10',
                'updated_at' => '2025-07-07 11:41:10',
            ],
            [
                'id' => 3,
                'nome' => 'LIQUIDI A DOSAGGIO AUTOMATICO',
                'percentuale_ricarico' => 0.00,
                'categoria_padre_id' => null,
                'created_at' => '2025-07-07 11:41:13',
                'updated_at' => '2025-07-07 11:41:13',
            ],
            [
                'id' => 4,
                'nome' => 'CUCINA ALIMENTARE',
                'percentuale_ricarico' => 0.00,
                'categoria_padre_id' => null,
                'created_at' => '2025-07-07 11:41:40',
                'updated_at' => '2025-07-07 11:41:40',
            ],
            [
                'id' => 5,
                'nome' => 'LAVAGGIO MECCANICO - DETERGENTI LIQUIDI',
                'percentuale_ricarico' => 0.00,
                'categoria_padre_id' => 4,
                'created_at' => '2025-07-07 11:41:49',
                'updated_at' => '2025-07-07 11:41:49',
            ],
            [
                'id' => 6,
                'nome' => 'LAVAGGIO MECCANICO - CAPSULE',
                'percentuale_ricarico' => 0.00,
                'categoria_padre_id' => 4,
                'created_at' => '2025-07-07 11:42:08',
                'updated_at' => '2025-07-07 11:42:08',
            ],
            [
                'id' => 7,
                'nome' => 'LAVAGGIO MECCANICO - BRILLANTANTI',
                'percentuale_ricarico' => 0.00,
                'categoria_padre_id' => 4,
                'created_at' => '2025-07-07 11:42:26',
                'updated_at' => '2025-07-07 11:42:26',
            ],
            [
                'id' => 8,
                'nome' => 'DETERGENTI E DISINCROSTANTI PER LAVASTOVIGLIE A DOSAGGIO MANUALE',
                'percentuale_ricarico' => 0.00,
                'categoria_padre_id' => 4,
                'created_at' => '2025-07-07 11:43:01',
                'updated_at' => '2025-07-07 11:43:01',
            ],
            [
                'id' => 9,
                'nome' => 'SALE PER LAVASTOVIGLIE',
                'percentuale_ricarico' => 0.00,
                'categoria_padre_id' => 4,
                'created_at' => '2025-07-07 11:43:23',
                'updated_at' => '2025-07-07 11:43:23',
            ],
            [
                'id' => 10,
                'nome' => 'LAVAGGIO MANUALE STOVIGLIE E ATTREZZATURE',
                'percentuale_ricarico' => 0.00,
                'categoria_padre_id' => 4,
                'created_at' => '2025-07-07 11:43:42',
                'updated_at' => '2025-07-07 11:43:42',
            ],
            [
                'id' => 11,
                'nome' => 'SGRASSANTI PER PIASTRE E FORNI',
                'percentuale_ricarico' => 0.00,
                'categoria_padre_id' => 4,
                'created_at' => '2025-07-07 11:43:55',
                'updated_at' => '2025-07-07 11:43:55',
            ],
            [
                'id' => 12,
                'nome' => 'SGRASSANTI PER LE SUPERFICI DELLE CUCINE',
                'percentuale_ricarico' => 0.00,
                'categoria_padre_id' => 4,
                'created_at' => '2025-07-07 11:44:09',
                'updated_at' => '2025-07-07 11:44:09',
            ],
            [
                'id' => 13,
                'nome' => 'PRODOTTI SPECIFICI METALLI  E ACCIAI',
                'percentuale_ricarico' => 0.00,
                'categoria_padre_id' => 4,
                'created_at' => '2025-07-07 11:44:25',
                'updated_at' => '2025-07-07 11:44:25',
            ],
        ]);
    }
}
