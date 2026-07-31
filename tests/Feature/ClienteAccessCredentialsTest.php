<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\Clientes\Pages\CreateCliente;
use App\Filament\Resources\Clientes\Pages\EditCliente;
use App\Models\Cliente;
use App\Models\User;
use App\Services\Clients\ClienteAccessService;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ClienteAccessCredentialsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $this->admin->assignRole(Role::query()->create(['name' => 'admin']));

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_amministratore_crea_cliente_account_e_password_utilizzabile_al_login(): void
    {
        Livewire::actingAs($this->admin)
            ->test(CreateCliente::class)
            ->fillForm([
                'nome' => 'Cliente con accesso',
                'partita_iva' => '12345678901',
                'email' => 'ACCESSO@EXAMPLE.COM',
                'access_password' => 'PasswordSicura1!',
                'access_password_confirmation' => 'PasswordSicura1!',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $cliente = Cliente::query()->where('partita_iva', '12345678901')->firstOrFail();
        $account = $cliente->accessUser()->firstOrFail();

        $this->assertSame('accesso@example.com', $cliente->email);
        $this->assertSame('accesso@example.com', $account->email);
        $this->assertSame('Cliente con accesso', $account->name);
        $this->assertTrue(Hash::check('PasswordSicura1!', $account->password));
        $this->assertTrue($account->hasRole('cliente'));

        auth()->logout();
        Filament::setCurrentPanel(Filament::getPanel('clienti'));

        Livewire::test(Login::class)
            ->fillForm([
                'email' => 'accesso@example.com',
                'password' => 'PasswordSicura1!',
            ])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $this->assertAuthenticatedAs($account);
    }

    public function test_email_e_password_sono_obbligatorie_per_un_nuovo_accesso(): void
    {
        Livewire::actingAs($this->admin)
            ->test(CreateCliente::class)
            ->fillForm([
                'nome' => 'Cliente incompleto',
                'partita_iva' => '12345678902',
            ])
            ->call('create')
            ->assertHasFormErrors([
                'email' => 'required',
                'access_password' => 'required',
            ]);

        $this->assertDatabaseMissing('clienti', ['partita_iva' => '12345678902']);
    }

    public function test_email_di_login_non_puo_essere_riutilizzata(): void
    {
        Livewire::actingAs($this->admin)
            ->test(CreateCliente::class)
            ->fillForm([
                'nome' => 'Cliente duplicato',
                'partita_iva' => '12345678903',
                'email' => $this->admin->email,
                'access_password' => 'PasswordSicura1!',
                'access_password_confirmation' => 'PasswordSicura1!',
            ])
            ->call('create')
            ->assertHasFormErrors(['email' => 'unique']);

        $this->assertDatabaseMissing('clienti', ['partita_iva' => '12345678903']);
    }

    public function test_modifica_cliente_preserva_o_aggiorna_la_password_solo_se_compilata(): void
    {
        $cliente = Cliente::query()->create([
            'nome' => 'Cliente iniziale',
            'partita_iva' => '12345678904',
            'email' => 'iniziale@example.com',
        ]);
        $account = app(ClienteAccessService::class)->synchronize($cliente, 'PasswordIniziale1!');
        $initialHash = $account->password;

        Livewire::actingAs($this->admin)
            ->test(EditCliente::class, ['record' => $cliente->getRouteKey()])
            ->fillForm([
                'nome' => 'Cliente aggiornato',
                'email' => 'NUOVA@EXAMPLE.COM',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $account->refresh();
        $this->assertSame($initialHash, $account->password);
        $this->assertSame('nuova@example.com', $account->email);
        $this->assertSame('Cliente aggiornato', $account->name);

        Livewire::actingAs($this->admin)
            ->test(EditCliente::class, ['record' => $cliente->getRouteKey()])
            ->fillForm([
                'access_password' => 'PasswordNuova2!',
                'access_password_confirmation' => 'PasswordNuova2!',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $account->refresh();
        $this->assertNotSame($initialHash, $account->password);
        $this->assertTrue(Hash::check('PasswordNuova2!', $account->password));
    }

    public function test_cliente_legacy_senza_account_deve_ricevere_una_password(): void
    {
        $cliente = Cliente::query()->create([
            'nome' => 'Cliente legacy',
            'partita_iva' => '12345678905',
        ]);

        Livewire::actingAs($this->admin)
            ->test(EditCliente::class, ['record' => $cliente->getRouteKey()])
            ->fillForm(['email' => 'legacy@example.com'])
            ->call('save')
            ->assertHasFormErrors(['access_password' => 'required']);

        $this->assertNull($cliente->accessUser()->first());
    }

    public function test_un_cliente_non_puo_avere_due_account_di_accesso(): void
    {
        $cliente = Cliente::query()->create([
            'nome' => 'Cliente singolo',
            'partita_iva' => '12345678906',
            'email' => 'singolo@example.com',
        ]);
        User::factory()->create(['cliente_id' => $cliente->id]);

        $this->expectException(QueryException::class);

        User::factory()->create(['cliente_id' => $cliente->id]);
    }
}
