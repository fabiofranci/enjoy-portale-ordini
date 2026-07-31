<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Fornitore;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SupplierSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SupplierSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_supplier_seeder_is_idempotent_and_updates_by_stable_code(): void
    {
        $this->seed(SupplierSeeder::class);

        $firstIds = Fornitore::query()->pluck('id', 'code')->all();
        Fornitore::query()->where('code', 'ICA')->update(['nome' => 'Nome non aggiornato']);

        $this->seed(SupplierSeeder::class);

        $this->assertDatabaseCount('fornitori', 2);
        $this->assertDatabaseHas('fornitori', ['code' => 'ICA', 'nome' => 'ICA']);
        $this->assertDatabaseHas('fornitori', ['code' => 'IGROUP', 'nome' => 'IGROUP']);
        $this->assertSame($firstIds, Fornitore::query()->pluck('id', 'code')->all());
        $this->assertNull(Fornitore::query()->where('code', 'ICA')->value('email'));
        $this->assertSame('code', (new Fornitore)->getRouteKeyName());
    }

    public function test_supplier_code_is_normalized_and_unique(): void
    {
        Fornitore::query()->create(['code' => 'ica', 'nome' => 'ICA']);

        $this->assertDatabaseHas('fornitori', ['code' => 'ICA']);
        $this->expectException(QueryException::class);

        Fornitore::query()->create(['code' => ' ICA ', 'nome' => 'Duplicato']);
    }

    public function test_supplier_code_is_required(): void
    {
        $this->expectException(QueryException::class);

        Fornitore::query()->create(['nome' => 'Senza codice']);
    }

    public function test_database_seeder_does_not_run_demo_seeders_in_production(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');

        try {
            $this->artisan('db:seed', [
                '--class' => DatabaseSeeder::class,
                '--force' => true,
            ])->assertSuccessful();

            $this->assertDatabaseCount('fornitori', 2);
            $this->assertDatabaseCount('users', 0);
            $this->assertDatabaseCount('Categorie', 0);
            $this->assertDatabaseCount('Clienti', 0);
        } finally {
            $this->app->detectEnvironment(static fn (): string => 'testing');
        }
    }
}
