<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\CentroCostos\CentroCostoResource;
use App\Filament\Resources\CentroCostos\Pages\EditCentroCosto;
use App\Filament\Resources\CentroCostos\RelationManagers\ListiniCommercialiRelationManager;
use App\Models\CentroCosto;
use App\Models\Cliente;
use App\Models\Fornitore;
use App\Models\Listino;
use App\Models\ListinoReferenza;
use App\Models\ReferenzaFornitore;
use App\Models\User;
use App\Services\Catalog\CentroCostoListinoService;
use App\Services\Catalog\Exceptions\ListinoAssignmentException;
use Database\Seeders\SupplierSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class CentroCostoListinoAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private CentroCosto $centroCosto;

    private CentroCostoListinoService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SupplierSeeder::class);
        $cliente = Cliente::query()->create([
            'nome' => 'Cliente test',
            'partita_iva' => '12345678901',
        ]);
        $this->centroCosto = CentroCosto::query()->create([
            'cliente_id' => $cliente->id,
            'nome' => 'Centro test',
        ]);
        $this->service = app(CentroCostoListinoService::class);
    }

    public function test_assegna_un_listino_ica_al_centro_di_costo(): void
    {
        $listino = $this->listino($this->supplier('ICA'), 'ICA Scuole');

        $this->service->assign($this->centroCosto, [$listino->id]);

        $this->assertDatabaseHas('centro_costo_listino', [
            'centro_costo_id' => $this->centroCosto->id,
            'listino_id' => $listino->id,
        ]);
        $this->assertSame('ICA', $this->centroCosto->fornitoreEffettivo()?->code);
    }

    public function test_assegna_due_listini_ica_senza_referenze_sovrapposte(): void
    {
        $supplier = $this->supplier('ICA');
        $first = $this->listino($supplier, 'ICA Scuole');
        $second = $this->listino($supplier, 'ICA Pulizia');
        $this->price($first, $this->reference($supplier, 'ICA-1'), 1.5);
        $this->price($second, $this->reference($supplier, 'ICA-2'), 2.5);

        $this->service->assign($this->centroCosto, [$first->id, $second->id]);

        $this->assertSame(2, $this->centroCosto->listiniCommerciali()->count());
        $this->assertDatabaseCount('centro_costo_listino', 2);
    }

    public function test_rifiuta_igroup_quando_il_centro_usa_ica(): void
    {
        $ica = $this->listino($this->supplier('ICA'), 'ICA Scuole');
        $igroup = $this->listino($this->supplier('IGROUP'), 'IGROUP Uffici');
        $this->service->assign($this->centroCosto, [$ica->id]);

        $exception = $this->captureFailure(
            fn () => $this->service->assign($this->centroCosto, [$igroup->id])
        );

        $this->assertStringContainsString('stesso fornitore', $exception->getMessage());
        $this->assertDatabaseCount('centro_costo_listino', 1);
    }

    public function test_puo_usare_igroup_dopo_la_rimozione_di_tutti_i_listini_ica(): void
    {
        $ica = $this->listino($this->supplier('ICA'), 'ICA Scuole');
        $igroup = $this->listino($this->supplier('IGROUP'), 'IGROUP Uffici');
        $this->service->assign($this->centroCosto, [$ica->id]);

        $this->service->remove($this->centroCosto, $ica);
        $this->service->assign($this->centroCosto, [$igroup->id]);

        $this->assertSame('IGROUP', $this->centroCosto->fornitoreEffettivo()?->code);
        $this->assertDatabaseMissing('centro_costo_listino', ['listino_id' => $ica->id]);
        $this->assertDatabaseHas('centro_costo_listino', ['listino_id' => $igroup->id]);
    }

    public function test_rifiuta_la_stessa_assegnazione_duplicata(): void
    {
        $listino = $this->listino($this->supplier('ICA'), 'ICA Scuole');
        $this->service->assign($this->centroCosto, [$listino->id]);

        $exception = $this->captureFailure(
            fn () => $this->service->assign($this->centroCosto, [$listino->id])
        );

        $this->assertStringContainsString('gia assegnati', $exception->getMessage());
        $this->assertDatabaseCount('centro_costo_listino', 1);
    }

    public function test_rifiuta_listini_con_la_stessa_referenza_e_ne_indica_il_codice(): void
    {
        $supplier = $this->supplier('ICA');
        $first = $this->listino($supplier, 'ICA Scuole');
        $second = $this->listino($supplier, 'ICA Pulizia');
        $sharedReference = $this->reference($supplier, 'ICA-SHARED');
        $this->price($first, $sharedReference, 10);
        $this->price($second, $sharedReference, 8);

        $exception = $this->captureFailure(
            fn () => $this->service->assign(
                $this->centroCosto,
                [$first->id, $second->id]
            )
        );

        $this->assertSame(['ICA-SHARED'], $exception->referenceCodes);
        $this->assertStringContainsString('1 referenza', $exception->getMessage());
        $this->assertStringContainsString('ICA-SHARED', $exception->getMessage());
        $this->assertDatabaseCount('centro_costo_listino', 0);
    }

    public function test_selezione_multipla_non_salva_parzialmente_se_un_listino_non_e_valido(): void
    {
        $supplier = $this->supplier('ICA');
        $valid = $this->listino($supplier, 'ICA Valido');
        $inactive = $this->listino($supplier, 'ICA Inattivo', false);

        $this->captureFailure(
            fn () => $this->service->assign(
                $this->centroCosto,
                [$valid->id, $inactive->id]
            )
        );

        $this->assertDatabaseCount('centro_costo_listino', 0);
    }

    public function test_listino_disattivato_non_e_assegnabile(): void
    {
        $listino = $this->listino($this->supplier('ICA'), 'ICA Inattivo', false);

        $exception = $this->captureFailure(
            fn () => $this->service->assign($this->centroCosto, [$listino->id])
        );

        $this->assertStringContainsString('non e attivo', $exception->getMessage());
        $this->assertDatabaseCount('centro_costo_listino', 0);
    }

    public function test_fornitore_disattivato_non_e_assegnabile(): void
    {
        $supplier = $this->supplier('ICA');
        $listino = $this->listino($supplier, 'ICA Scuole');
        $supplier->update(['attivo' => false]);

        $exception = $this->captureFailure(
            fn () => $this->service->assign($this->centroCosto, [$listino->id])
        );

        $this->assertStringContainsString('fornitore', $exception->getMessage());
        $this->assertDatabaseCount('centro_costo_listino', 0);
    }

    public function test_assegnazione_resta_visibile_e_segnalata_se_il_listino_viene_disattivato(): void
    {
        $listino = $this->listino($this->supplier('ICA'), 'ICA Scuole');
        $this->service->assign($this->centroCosto, [$listino->id]);

        $listino->update(['attivo' => false]);
        $listino->refresh()->load('fornitore');

        $this->assertDatabaseHas('centro_costo_listino', [
            'centro_costo_id' => $this->centroCosto->id,
            'listino_id' => $listino->id,
        ]);
        $this->assertTrue(
            $this->centroCosto->listiniCommerciali()->whereKey($listino->id)->exists()
        );
        $this->assertSame(
            'Listino inattivo - da correggere',
            ListiniCommercialiRelationManager::assignmentStatus($listino)
        );
    }

    public function test_relazioni_eloquent_e_fornitore_effettivo_sono_coerenti_con_il_legacy(): void
    {
        $commercial = $this->listino($this->supplier('ICA'), 'ICA Scuole');
        $legacy = Listino::query()->create([
            'nome_listino' => 'Vendita legacy',
            'tipo' => 'vendita',
            'attivo' => true,
        ]);
        $this->service->assign($this->centroCosto, [$commercial->id]);
        $this->centroCosto->listini()->attach($legacy->id);

        $this->assertSame(2, $this->centroCosto->listini()->count());
        $this->assertSame([$commercial->id], $this->centroCosto
            ->listiniCommerciali()
            ->pluck('Listini.id')
            ->all());
        $this->assertTrue($commercial->centriCosto()->whereKey($this->centroCosto->id)->exists());
        $this->assertSame('ICA', $this->centroCosto->fornitoreEffettivo()?->code);
    }

    public function test_referenze_disponibili_sono_attive_ordinabili_e_senza_duplicati(): void
    {
        $supplier = $this->supplier('ICA');
        $first = $this->listino($supplier, 'ICA Scuole');
        $second = $this->listino($supplier, 'ICA Pulizia');
        $available = $this->reference($supplier, 'ICA-AVAILABLE');
        $notOrderableReference = $this->reference($supplier, 'ICA-REF-OFF', false);
        $notOrderablePrice = $this->reference($supplier, 'ICA-PRICE-OFF');
        $this->price($first, $available, 3.2);
        $this->price($first, $notOrderableReference, 4.2);
        $this->price($second, $notOrderablePrice, 5.2, false);
        $this->service->assign($this->centroCosto, [$first->id, $second->id]);

        $references = $this->centroCosto->referenzeDisponibili();

        $this->assertCount(1, $references);
        $this->assertSame('ICA-AVAILABLE', $references->first()?->referenza->supplier_code);
        $this->assertSame(
            $references->pluck('referenza_fornitore_id')->unique()->count(),
            $references->count()
        );
    }

    public function test_prezzo_disponibile_corrisponde_al_listino_assegnato(): void
    {
        $supplier = $this->supplier('ICA');
        $listino = $this->listino($supplier, 'ICA Scuole');
        $reference = $this->reference($supplier, 'ICA-PRICE');
        $this->price($listino, $reference, 7.35);
        $this->service->assign($this->centroCosto, [$listino->id]);

        $price = $this->centroCosto->referenzeDisponibili()->firstOrFail();

        $this->assertSame($listino->id, $price->listino_id);
        $this->assertSame($reference->id, $price->referenza_fornitore_id);
        $this->assertSame('7.35000', $price->prezzo);
    }

    public function test_amministratore_puo_aprire_la_gestione_del_centro_di_costo(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::query()->create(['name' => 'admin']));

        $this->actingAs($admin)
            ->get(CentroCostoResource::getUrl('edit', ['record' => $this->centroCosto]))
            ->assertOk();
    }

    public function test_cliente_non_puo_aprire_la_gestione_del_centro_di_costo(): void
    {
        $client = User::factory()->create();
        $client->assignRole(Role::query()->create(['name' => 'cliente']));

        $this->actingAs($client)
            ->get(CentroCostoResource::getUrl('edit', ['record' => $this->centroCosto]))
            ->assertForbidden();
    }

    public function test_interfaccia_filament_mostra_l_errore_restituito_dal_servizio(): void
    {
        $supplier = $this->supplier('ICA');
        $first = $this->listino($supplier, 'ICA Scuole');
        $second = $this->listino($supplier, 'ICA Pulizia');
        $sharedReference = $this->reference($supplier, 'ICA-SHARED');
        $this->price($first, $sharedReference, 10);
        $this->price($second, $sharedReference, 8);
        $admin = User::factory()->create();
        $admin->assignRole(Role::query()->create(['name' => 'admin']));
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $component = Livewire::test(ListiniCommercialiRelationManager::class, [
            'ownerRecord' => $this->centroCosto,
            'pageClass' => EditCentroCosto::class,
        ])
            ->callTableAction('assegnaListini', data: [
                'listino_ids' => [$first->id, $second->id],
            ]);

        $component->assertHasTableActionErrors(['listino_ids']);
        $this->assertSame(
            [
                'Assegnazione bloccata: 1 referenza e presente in piu listini (ICA-SHARED).',
            ],
            $component->errors()->get('mountedActions.0.data.listino_ids')
        );

        $this->assertDatabaseCount('centro_costo_listino', 0);
    }

    public function test_migration_crea_il_flag_fornitore_attivo_con_default_compatibile(): void
    {
        $this->assertTrue(Schema::hasColumn('fornitori', 'attivo'));

        $supplier = Fornitore::query()->create([
            'code' => 'TEST',
            'nome' => 'Test',
        ]);

        $this->assertTrue($supplier->refresh()->attivo);
    }

    public function test_migration_del_flag_fornitore_attivo_supporta_rollback_e_riesecuzione(): void
    {
        $migration = require database_path(
            'migrations/2026_07_31_000003_add_attivo_to_fornitori_table.php'
        );

        $migration->down();
        $this->assertFalse(Schema::hasColumn('fornitori', 'attivo'));

        $migration->up();
        $this->assertTrue(Schema::hasColumn('fornitori', 'attivo'));
    }

    public function test_selettore_esclude_listini_incompatibili_duplicati_inattivi_e_sovrapposti(): void
    {
        $ica = $this->supplier('ICA');
        $igroup = $this->supplier('IGROUP');
        $assigned = $this->listino($ica, 'ICA Assegnato');
        $compatible = $this->listino($ica, 'ICA Compatibile');
        $overlapping = $this->listino($ica, 'ICA Sovrapposto');
        $inactive = $this->listino($ica, 'ICA Inattivo', false);
        $otherSupplier = $this->listino($igroup, 'IGROUP Uffici');
        $shared = $this->reference($ica, 'ICA-SHARED');
        $this->price($assigned, $shared, 1);
        $this->price($overlapping, $shared, 2);
        $this->service->assign($this->centroCosto, [$assigned->id]);

        $availableIds = $this->service
            ->availableListini($this->centroCosto)
            ->pluck('id')
            ->all();

        $this->assertSame([$compatible->id], $availableIds);
        $this->assertNotContains($assigned->id, $availableIds);
        $this->assertNotContains($overlapping->id, $availableIds);
        $this->assertNotContains($inactive->id, $availableIds);
        $this->assertNotContains($otherSupplier->id, $availableIds);
    }

    public function test_servizio_non_rimuove_assegnazioni_vendita_legacy(): void
    {
        $legacy = Listino::query()->create([
            'nome_listino' => 'Vendita legacy',
            'tipo' => 'vendita',
            'attivo' => true,
        ]);
        $this->centroCosto->listini()->attach($legacy->id);

        $this->captureFailure(
            fn () => $this->service->remove($this->centroCosto, $legacy)
        );

        $this->assertDatabaseHas('centro_costo_listino', [
            'centro_costo_id' => $this->centroCosto->id,
            'listino_id' => $legacy->id,
        ]);
    }

    private function supplier(string $code): Fornitore
    {
        return Fornitore::query()->where('code', $code)->firstOrFail();
    }

    private function listino(
        Fornitore $supplier,
        string $name,
        bool $active = true
    ): Listino {
        return Listino::query()->create([
            'fornitore_id' => $supplier->id,
            'nome_listino' => $name,
            'tipo' => 'acquisto',
            'attivo' => $active,
        ]);
    }

    private function reference(
        Fornitore $supplier,
        string $code,
        bool $orderable = true
    ): ReferenzaFornitore {
        return ReferenzaFornitore::query()->create([
            'fornitore_id' => $supplier->id,
            'supplier_code' => $code,
            'descrizione' => "Referenza {$code}",
            'ordinabile' => $orderable,
            'source_hash' => hash('sha256', $supplier->code.$code),
        ]);
    }

    private function price(
        Listino $listino,
        ReferenzaFornitore $reference,
        float $price,
        bool $orderable = true
    ): ListinoReferenza {
        return ListinoReferenza::query()->create([
            'listino_id' => $listino->id,
            'referenza_fornitore_id' => $reference->id,
            'prezzo' => $price,
            'ordinabile' => $orderable,
        ]);
    }

    private function captureFailure(callable $callback): ListinoAssignmentException
    {
        try {
            $callback();
        } catch (ListinoAssignmentException $exception) {
            return $exception;
        }

        $this->fail('L operazione non valida doveva essere rifiutata.');
    }
}
