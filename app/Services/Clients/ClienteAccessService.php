<?php

declare(strict_types=1);

namespace App\Services\Clients;

use App\Models\Cliente;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Spatie\Permission\Models\Role;

final class ClienteAccessService
{
    public function synchronize(Cliente $cliente, ?string $plainPassword = null): User
    {
        return DB::transaction(function () use ($cliente, $plainPassword): User {
            $email = Str::lower(trim((string) $cliente->email));

            if ($email === '') {
                throw new InvalidArgumentException('L\'email di accesso del cliente e obbligatoria.');
            }

            $user = $cliente->accessUser()->first();

            if ($user === null && ($plainPassword === null || $plainPassword === '')) {
                throw new InvalidArgumentException('La password e obbligatoria per creare l\'accesso cliente.');
            }

            if ($cliente->email !== $email) {
                $cliente->forceFill(['email' => $email])->save();
            }

            $user ??= new User;
            $user->fill([
                'name' => $cliente->nome,
                'email' => $email,
                'cliente_id' => $cliente->getKey(),
            ]);

            if ($plainPassword !== null && $plainPassword !== '') {
                $user->password = $plainPassword;
            }

            $user->save();

            $clientRole = Role::query()->firstOrCreate([
                'name' => 'cliente',
                'guard_name' => 'web',
            ]);
            $user->assignRole($clientRole);

            return $user;
        });
    }
}
