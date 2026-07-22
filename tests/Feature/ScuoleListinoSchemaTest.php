<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\CentroCosto;
use App\Models\Cliente;
use App\Models\Listino;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ScuoleListinoSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_listino_prodotto_defaults_and_five_decimal_prices_can_be_saved(): void
    {
        $categoria = Categoria::create([
            'nome' => 'Detergenti',
            'percentuale_ricarico' => 0,
        ]);

        $product = Product::create([
            'nome' => 'Sapone scuola',
            'codice' => 'SKU-SCUOLA-001',
            'categoria_id' => $categoria->id,
            'unita_misura' => 'NR',
            'disponibile' => true,
        ]);

        $listino = Listino::create([
            'nome_listino' => 'Scuole',
            'tipo' => 'vendita',
            'sconto_percentuale' => 0,
            'valido_dal' => now()->toDateString(),
        ]);

        $listino->products()->attach($product->id, [
            'prezzo_lordo' => '15.43210',
            'sconto_percentuale' => '0.00',
            'prezzo' => '12.34567',
            'iva_percentuale' => '22.00',
            'prezzo_sorgente' => '12.34567',
            'unita_prezzo_sorgente' => 'NR',
        ]);

        $pivot = DB::table('listino_prodotto')
            ->where('listino_id', $listino->id)
            ->where('product_id', $product->id)
            ->first();

        $this->assertNotNull($pivot);
        $this->assertSame(1, (int) $pivot->ordinabile);
        $this->assertNull($pivot->motivo_non_ordinabile);
        $this->assertSame('15.43210', number_format((float) $pivot->prezzo_lordo, 5, '.', ''));
        $this->assertSame('12.34567', number_format((float) $pivot->prezzo, 5, '.', ''));
        $this->assertSame('12.34567', number_format((float) $pivot->prezzo_sorgente, 5, '.', ''));
        $this->assertSame('NR', $pivot->unita_prezzo_sorgente);
    }

    public function test_centro_costo_can_be_associated_to_listino_only_once(): void
    {
        $cliente = Cliente::create([
            'nome' => 'Scuola Primaria',
            'partita_iva' => '12345678901',
        ]);

        $centroCosto = CentroCosto::create([
            'cliente_id' => $cliente->id,
            'nome' => 'Plesso A',
        ]);

        $listino = Listino::create([
            'nome_listino' => 'Scuole',
            'tipo' => 'vendita',
            'sconto_percentuale' => 0,
            'valido_dal' => now()->toDateString(),
        ]);

        $centroCosto->listini()->attach($listino->id);

        $this->assertDatabaseHas('centro_costo_listino', [
            'centro_costo_id' => $centroCosto->id,
            'listino_id' => $listino->id,
        ]);

        try {
            $centroCosto->listini()->attach($listino->id);
            $this->fail('Duplicate centro_costo_listino association was inserted.');
        } catch (QueryException) {
            $this->assertDatabaseCount('centro_costo_listino', 1);
        }
    }
}
