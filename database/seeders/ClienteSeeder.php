<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

use App\Models\Cliente;
use App\Models\CentroCosto;

class ClienteSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $cliente = Cliente::create([
            'nome' => 'Enjoy Service Srl',
            'partita_iva' => '14418141009',
            'email' => 'info@enjoy.it',
            'telefono' => '029999999',
            'indirizzo' => 'Via Piero della Francesca 4, Rho (MI)',
        ]);

        CentroCosto::create([
            'cliente_id' => $cliente->id,
            'nome' => 'Scuola Primaria Rho',
            'descrizione' => 'Centro Rho',
            'budget_annuale' => 10000,
            'budget_mensile' => 1000,
        ]);

        CentroCosto::create([
            'cliente_id' => $cliente->id,
            'nome' => 'Scuola Secondaria Rho',
            'descrizione' => 'Centro Rho 2',
            'budget_annuale' => 12000,
            'budget_mensile' => 1200,
        ]);
    }
}
