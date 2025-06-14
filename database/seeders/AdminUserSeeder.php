<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        // Crea il ruolo se non esiste
        $adminRole = Role::firstOrCreate(['name' => 'admin']);

        // Crea l'utente
        $user = User::firstOrCreate(
            ['email' => 'admin@enjoy.it'],
            [
                'name' => 'fabio',
                'password' => 'geppett0',
            ]
        );

        // Assegna il ruolo
        $user->assignRole($adminRole);
    }
}
