<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\CentroCosto;
use App\Models\Cliente;
use App\Models\Listino;
use App\Models\Product;
use App\Models\User;
use App\Services\PrezziService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ScuoleListinoAssociationAndOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_associate_command_links_centro_costo_to_listino_without_duplicates(): void
    {
        $setup = $this->scuoleSetup();

        $this->artisan('scuole:associate-listino', [
            '--centro-costo-id' => $setup['centroCosto']->id,
            '--listino' => 'Scuole',
            '--execute' => true,
        ])->assertSuccessful();

        $this->artisan('scuole:associate-listino', [
            '--centro-costo-id' => $setup['centroCosto']->id,
            '--listino' => 'Scuole',
            '--execute' => true,
        ])->assertSuccessful();

        $this->assertDatabaseCount('centro_costo_listino', 1);
        $this->assertDatabaseHas('centro_costo_listino', [
            'centro_costo_id' => $setup['centroCosto']->id,
            'listino_id' => $setup['listino']->id,
        ]);
    }

    public function test_prezzi_service_selects_scuole_listino_for_cliente_user(): void
    {
        $setup = $this->scuoleSetup();
        $setup['centroCosto']->listini()->syncWithoutDetaching([$setup['listino']->id]);
        PrezziService::clearCaches();

        $pricing = PrezziService::prezzoVisibile($setup['product'], $setup['user']);

        $this->assertSame($setup['listino']->id, $pricing['listino_id']);
        $this->assertTrue($pricing['ordinabile']);
        $this->assertSame(10.0, $pricing['prezzo']);
    }

    /**
     * @return array{cliente:Cliente, centroCosto:CentroCosto, listino:Listino, categoria:Categoria, product:Product, user:User}
     */
    private function scuoleSetup(bool $ordinabile = true, ?float $price = 10.0, ?string $reason = null): array
    {
        $cliente = Cliente::create([
            'nome' => 'SCUOLE',
            'partita_iva' => '12345678901',
        ]);
        $centroCosto = CentroCosto::create([
            'cliente_id' => $cliente->id,
            'nome' => 'SCUOLE',
        ]);
        $listino = Listino::create([
            'nome_listino' => 'Scuole',
            'tipo' => 'vendita',
            'sconto_percentuale' => 0,
            'valido_dal' => now()->subDay()->toDateString(),
            'valido_al' => now()->addYear()->toDateString(),
            'attivo' => true,
        ]);
        $categoria = Categoria::create([
            'nome' => 'Detergenti',
            'percentuale_ricarico' => 0,
        ]);
        $product = Product::create([
            'nome' => 'Prodotto scuola',
            'codice' => 'SKU-SCUOLE',
            'categoria_id' => $categoria->id,
            'unita_misura' => 'NR',
            'disponibile' => true,
        ]);
        $listino->products()->attach($product->id, [
            'prezzo_lordo' => $price,
            'sconto_percentuale' => 0,
            'prezzo' => $price,
            'iva_percentuale' => 22,
            'ordinabile' => $ordinabile,
            'motivo_non_ordinabile' => $reason,
            'prezzo_sorgente' => $price,
            'unita_prezzo_sorgente' => 'NR',
        ]);
        $user = User::create([
            'name' => 'Utente Scuole',
            'email' => 'scuole@example.test',
            'password' => 'password',
            'cliente_id' => $cliente->id,
        ]);

        DB::table('ordini')->delete();
        PrezziService::clearCaches();

        return compact('cliente', 'centroCosto', 'listino', 'categoria', 'product', 'user');
    }
}
