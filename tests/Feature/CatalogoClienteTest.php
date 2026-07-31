<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Client\Resources\Prodotti\Pages\ListProdotti;
use App\Filament\Client\Resources\Prodotti\ProdottoResource;
use App\Models\Categoria;
use App\Models\CentroCosto;
use App\Models\Cliente;
use App\Models\Fornitore;
use App\Models\Listino;
use App\Models\ListinoReferenza;
use App\Models\Product;
use App\Models\ReferenzaFornitore;
use App\Models\ReferenzaPackaging;
use App\Models\User;
use App\Services\Catalog\CatalogoClienteService;
use App\Services\Catalog\Exceptions\CatalogoClienteIncoerenteException;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class CatalogoClienteTest extends TestCase
{
    use RefreshDatabase;

    private CatalogoClienteService $service;

    private Cliente $cliente;

    private CentroCosto $centroCosto;

    private Fornitore $ica;

    private Fornitore $igroup;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(CatalogoClienteService::class);
        $this->cliente = $this->client('Cliente test', '11111111111');
        $this->centroCosto = $this->center($this->cliente, 'Sede principale');
        $this->ica = $this->supplier('ICA');
        $this->igroup = $this->supplier('IGROUP');
    }

    public function test_catalogo_e_costruito_dal_listino_assegnato(): void
    {
        $listino = $this->priceList($this->ica, 'ICA Scuole');
        $reference = $this->reference($this->ica, 'ICA-001');
        $this->price($listino, $reference, 1.25);
        $this->assign($this->centroCosto, $listino);

        $items = $this->service->items($this->centroCosto);

        $this->assertCount(1, $items);
        $this->assertSame('ICA-001', $items->first()?->supplierCode);
        $this->assertSame($listino->id, $items->first()?->listinoId);
        $this->assertSame('ICA', $items->first()?->fornitoreCode);
    }

    public function test_catalogo_unisce_due_listini_dello_stesso_fornitore(): void
    {
        $first = $this->priceList($this->ica, 'ICA Scuole');
        $second = $this->priceList($this->ica, 'ICA Pulizia');
        $this->price($first, $this->reference($this->ica, 'ICA-001'), 1);
        $this->price($second, $this->reference($this->ica, 'ICA-002'), 2);
        $this->assign($this->centroCosto, $first, $second);

        $this->assertSame(
            ['ICA-001', 'ICA-002'],
            $this->service->items($this->centroCosto)->pluck('supplierCode')->sort()->values()->all(),
        );
    }

    public function test_prezzo_e_nome_listino_provengono_da_listino_referenze(): void
    {
        $listino = $this->priceList($this->ica, 'Listino assegnato');
        $reference = $this->reference($this->ica, 'ICA-PRICE');
        $this->price($listino, $reference, 7.35, ['prezzo_sorgente' => 99.99]);
        $this->assign($this->centroCosto, $listino);

        $item = $this->service->items($this->centroCosto)->firstOrFail();

        $this->assertSame('7.35000', $item->prezzo);
        $this->assertSame('Listino assegnato', $item->listinoNome);
    }

    public function test_esclude_listini_non_assegnati(): void
    {
        $assigned = $this->priceList($this->ica, 'Assegnato');
        $notAssigned = $this->priceList($this->ica, 'Non assegnato');
        $this->price($assigned, $this->reference($this->ica, 'VISIBLE'), 1);
        $this->price($notAssigned, $this->reference($this->ica, 'HIDDEN'), 2);
        $this->assign($this->centroCosto, $assigned);

        $this->assertSame(
            ['VISIBLE'],
            $this->service->items($this->centroCosto)->pluck('supplierCode')->all(),
        );
    }

    public function test_esclude_referenze_di_un_fornitore_diverso_dal_listino(): void
    {
        $listino = $this->priceList($this->ica, 'ICA Scuole');
        $valid = $this->reference($this->ica, 'ICA-VALID');
        $invalid = $this->reference($this->igroup, 'IGROUP-CORRUPT');
        $this->price($listino, $valid, 1);
        DB::table('listino_referenze')->insert([
            'listino_id' => $listino->id,
            'referenza_fornitore_id' => $invalid->id,
            'prezzo' => 2,
            'attivo' => true,
            'ordinabile' => true,
            'modificato_manualmente' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assign($this->centroCosto, $listino);

        $this->assertSame(
            ['ICA-VALID'],
            $this->service->items($this->centroCosto)->pluck('supplierCode')->all(),
        );
    }

    public function test_esclude_un_listino_disattivato_senza_rimuovere_l_assegnazione(): void
    {
        $listino = $this->priceList($this->ica, 'Inattivo', ['attivo' => false]);
        $this->price($listino, $this->reference($this->ica, 'ICA-001'), 1);
        $this->assign($this->centroCosto, $listino);

        $this->assertTrue($this->service->items($this->centroCosto)->isEmpty());
        $this->assertTrue($this->centroCosto->listini()->whereKey($listino->id)->exists());
        $this->assertTrue($this->service->hasUnavailableAssignments($this->centroCosto));
    }

    public function test_esclude_un_fornitore_disattivato(): void
    {
        $listino = $this->priceList($this->ica, 'ICA Scuole');
        $this->price($listino, $this->reference($this->ica, 'ICA-001'), 1);
        $this->assign($this->centroCosto, $listino);
        $this->ica->update(['attivo' => false]);

        $this->assertTrue($this->service->items($this->centroCosto)->isEmpty());
        $this->assertTrue($this->service->hasUnavailableAssignments($this->centroCosto));
    }

    public function test_esclude_listino_referenza_non_attiva_o_non_ordinabile(): void
    {
        $listino = $this->priceList($this->ica, 'ICA Scuole');
        $this->price(
            $listino,
            $this->reference($this->ica, 'PRICE-INACTIVE'),
            1,
            ['attivo' => false],
        );
        $this->price(
            $listino,
            $this->reference($this->ica, 'PRICE-NOT-ORDERABLE'),
            2,
            ['ordinabile' => false],
        );
        $this->price(
            $listino,
            $this->reference($this->ica, 'PRICE-NULL'),
            3,
            ['prezzo' => null],
        );
        $this->assign($this->centroCosto, $listino);

        $this->assertTrue($this->service->items($this->centroCosto)->isEmpty());
    }

    public function test_esclude_referenza_fornitore_non_attiva_o_non_ordinabile(): void
    {
        $listino = $this->priceList($this->ica, 'ICA Scuole');
        $inactive = $this->reference($this->ica, 'REF-INACTIVE', ['attivo' => false]);
        $notOrderable = $this->reference($this->ica, 'REF-NOT-ORDERABLE', ['ordinabile' => false]);
        $this->price($listino, $inactive, 1);
        $this->price($listino, $notOrderable, 2);
        $this->assign($this->centroCosto, $listino);

        $this->assertTrue($this->service->items($this->centroCosto)->isEmpty());
    }

    public function test_rispetta_valido_dal_e_valido_al_inclusi_gli_estremi(): void
    {
        $current = $this->priceList($this->ica, 'Corrente', [
            'valido_dal' => today(),
            'valido_al' => today(),
        ]);
        $future = $this->priceList($this->ica, 'Futuro', ['valido_dal' => today()->addDay()]);
        $expired = $this->priceList($this->ica, 'Scaduto', ['valido_al' => today()->subDay()]);
        $this->price($current, $this->reference($this->ica, 'CURRENT'), 1);
        $this->price($future, $this->reference($this->ica, 'FUTURE'), 2);
        $this->price($expired, $this->reference($this->ica, 'EXPIRED'), 3);
        $this->assign($this->centroCosto, $current, $future, $expired);

        $this->assertSame(
            ['CURRENT'],
            $this->service->items($this->centroCosto)->pluck('supplierCode')->all(),
        );
    }

    public function test_restituisce_packaging_e_unita_commerciali(): void
    {
        $listino = $this->priceList($this->ica, 'ICA Scuole');
        $reference = $this->reference($this->ica, 'ICA-PACK', ['sales_unit' => 'CT']);
        ReferenzaPackaging::query()->create([
            'referenza_fornitore_id' => $reference->id,
            'unita_contenitore' => 'CT',
            'unita_contenuta' => 'NR',
            'quantita' => 6,
            'livello' => 1,
        ]);
        $this->price($listino, $reference, 12, ['price_unit' => 'CT']);
        $this->assign($this->centroCosto, $listino);

        $item = $this->service->items($this->centroCosto)->firstOrFail();

        $this->assertSame('CT', $item->priceUnit);
        $this->assertSame('CT', $item->salesUnit);
        $this->assertSame([
            'unita_contenitore' => 'CT',
            'unita_contenuta' => 'NR',
            'quantita' => '6.00000',
        ], $item->packaging[0]);
    }

    public function test_restituisce_e_visualizza_un_immagine_certa(): void
    {
        $price = $this->catalogPrice('WITH-IMAGE', ['immagine_path' => 'catalogo/ica/item.jpg']);
        $record = $this->service->query($this->centroCosto)->firstOrFail();

        $this->assertSame(
            'catalogo/ica/item.jpg',
            $this->service->items($this->centroCosto)->first()?->immaginePath,
        );
        $rendered = view('filament.client.prodotti.columns.catalogo-immagine', [
            'record' => $record,
        ])->render();
        $this->assertStringContainsString('<img', $rendered);
        $this->assertStringContainsString('src="/storage/catalogo/ica/item.jpg"', $rendered);
        $this->assertStringNotContainsString('src="http', $rendered);
        $this->assertSame($price->id, $record->id);
    }

    public function test_usa_un_fallback_locale_quando_l_immagine_manca(): void
    {
        $this->catalogPrice('NO-IMAGE');
        $record = $this->service->query($this->centroCosto)->firstOrFail();
        $rendered = view('filament.client.prodotti.columns.catalogo-immagine', [
            'record' => $record,
        ])->render();

        $this->assertNull($this->service->items($this->centroCosto)->first()?->immaginePath);
        $this->assertStringContainsString('Immagine non disponibile', $rendered);
        $this->assertStringNotContainsString('src="http://', $rendered);
        $this->assertStringNotContainsString('src="https://', $rendered);
    }

    public function test_ricerca_per_descrizione_e_limitata_al_centro(): void
    {
        $assigned = $this->priceList($this->ica, 'Assegnato');
        $unassigned = $this->priceList($this->ica, 'Non assegnato');
        $visible = $this->reference($this->ica, 'VISIBLE', ['descrizione' => 'Detergente limone']);
        $hidden = $this->reference($this->ica, 'HIDDEN', ['descrizione' => 'Detergente limone segreto']);
        $this->price($assigned, $visible, 1);
        $this->price($unassigned, $hidden, 2);
        $this->assign($this->centroCosto, $assigned);

        $this->assertSame(
            ['VISIBLE'],
            $this->service->items($this->centroCosto, 'limone')->pluck('supplierCode')->all(),
        );
    }

    public function test_ricerca_per_codici_fornitore_e_cliente_e_filtro_categoria(): void
    {
        $listino = $this->priceList($this->ica, 'ICA Scuole');
        $first = $this->reference($this->ica, 'SUP-ALPHA', [
            'customer_article_code' => 'CUSTOM-001',
            'categoria' => 'Carta',
        ]);
        $second = $this->reference($this->ica, 'SUP-BETA', ['categoria' => 'Pulizia']);
        $this->price($listino, $first, 1);
        $this->price($listino, $second, 2);
        $this->assign($this->centroCosto, $listino);

        $this->assertSame('SUP-ALPHA', $this->service->items($this->centroCosto, 'ALPHA')->first()?->supplierCode);
        $this->assertSame('SUP-ALPHA', $this->service->items($this->centroCosto, 'CUSTOM-001')->first()?->supplierCode);
        $this->assertSame(
            ['SUP-ALPHA'],
            $this->service->items($this->centroCosto, category: 'Carta')->pluck('supplierCode')->all(),
        );
        $this->assertSame(['Carta' => 'Carta', 'Pulizia' => 'Pulizia'], $this->service->categoryOptions($this->centroCosto));
    }

    public function test_customer_article_code_duplicato_non_perde_referenze(): void
    {
        $listino = $this->priceList($this->ica, 'ICA Scuole');
        $first = $this->reference($this->ica, 'SUP-001', ['customer_article_code' => 'DUPLICATO']);
        $second = $this->reference($this->ica, 'SUP-002', ['customer_article_code' => 'DUPLICATO']);
        $this->price($listino, $first, 1);
        $this->price($listino, $second, 2);
        $this->assign($this->centroCosto, $listino);

        $items = $this->service->items($this->centroCosto, 'DUPLICATO');

        $this->assertCount(2, $items);
        $this->assertSame(['SUP-001', 'SUP-002'], $items->pluck('supplierCode')->sort()->values()->all());
    }

    public function test_query_non_restituisce_duplicati_nell_unione_dei_listini(): void
    {
        $first = $this->priceList($this->ica, 'Primo');
        $second = $this->priceList($this->ica, 'Secondo');
        $this->price($first, $this->reference($this->ica, 'ONE'), 1);
        $this->price($second, $this->reference($this->ica, 'TWO'), 2);
        $this->assign($this->centroCosto, $first, $second);

        $ids = $this->service->query($this->centroCosto)->pluck('listino_referenze.id');

        $this->assertCount(2, $ids);
        $this->assertCount(2, $ids->unique());
    }

    public function test_rileva_prezzi_multipli_senza_sceglierne_uno(): void
    {
        $first = $this->priceList($this->ica, 'Primo');
        $second = $this->priceList($this->ica, 'Secondo');
        $reference = $this->reference($this->ica, 'DUPLICATE-PRICE');
        $this->price($first, $reference, 10);
        $this->price($second, $reference, 8);
        $this->assign($this->centroCosto, $first, $second);

        try {
            $this->service->items($this->centroCosto);
        } catch (CatalogoClienteIncoerenteException $exception) {
            $this->assertSame(['DUPLICATE-PRICE'], $exception->supplierCodes);

            return;
        }

        $this->fail('La configurazione con prezzi multipli doveva essere rifiutata.');
    }

    public function test_cliente_con_un_solo_centro_lo_seleziona_automaticamente(): void
    {
        $user = $this->clientUser($this->cliente);
        $this->useClientPanel();

        Livewire::actingAs($user)
            ->test(ListProdotti::class)
            ->assertSet('centroCostoId', $this->centroCosto->id)
            ->assertSee('Ordini in fase di attivazione');
    }

    public function test_cliente_con_piu_centri_puo_selezionarli_e_la_scelta_persiste_in_sessione(): void
    {
        $second = $this->center($this->cliente, 'Seconda sede');
        $user = $this->clientUser($this->cliente);
        $this->useClientPanel();

        Livewire::actingAs($user)
            ->test(ListProdotti::class)
            ->assertSet('centroCostoId', null)
            ->callAction('selezionaCentro', data: ['centro_costo_id' => $second->id])
            ->assertSet('centroCostoId', $second->id);

        $this->assertSame($second->id, session('catalogo.centro_costo_id'));
    }

    public function test_servizio_rifiuta_il_centro_di_un_altro_cliente(): void
    {
        $other = $this->client('Altro cliente', '22222222222');
        $otherCenter = $this->center($other, 'Centro riservato');
        $user = $this->clientUser($this->cliente);

        $this->expectException(AuthorizationException::class);

        $this->service->centroAccessibile($user, $otherCenter->id);
    }

    public function test_query_string_manipolata_per_un_altro_cliente_e_rifiutata(): void
    {
        $other = $this->client('Altro cliente', '22222222222');
        $otherCenter = $this->center($other, 'Centro riservato');
        $user = $this->clientUser($this->cliente);
        $this->useClientPanel();

        $this->actingAs($user)
            ->get(ProdottoResource::getUrl('index', ['centro' => $otherCenter->id]))
            ->assertForbidden();
    }

    public function test_payload_livewire_manipolato_per_un_altro_cliente_e_rifiutato(): void
    {
        $other = $this->client('Altro cliente', '22222222222');
        $otherCenter = $this->center($other, 'Centro riservato');
        $user = $this->clientUser($this->cliente);
        $this->useClientPanel();

        $component = Livewire::actingAs($user)->test(ListProdotti::class);
        $component->instance()->centroCostoId = $otherCenter->id;

        $this->expectException(AuthorizationException::class);

        $component->instance()->getSubheading();
    }

    public function test_sessione_manipolata_per_un_altro_cliente_e_rifiutata(): void
    {
        $other = $this->client('Altro cliente', '22222222222');
        $otherCenter = $this->center($other, 'Centro riservato');
        $user = $this->clientUser($this->cliente);
        $this->useClientPanel();

        $this->withSession(['catalogo.centro_costo_id' => $otherCenter->id])
            ->actingAs($user)
            ->get(ProdottoResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_cliente_senza_listini_riceve_uno_stato_vuoto_controllato(): void
    {
        $user = $this->clientUser($this->cliente);
        $this->useClientPanel();

        Livewire::actingAs($user)
            ->test(ListProdotti::class)
            ->assertSee('Nessun articolo disponibile')
            ->assertSee('Non risultano listini attivi con articoli ordinabili');
    }

    public function test_cliente_senza_centri_riceve_uno_stato_vuoto_controllato(): void
    {
        $client = $this->client('Senza centri', '33333333333');
        $user = $this->clientUser($client);
        $this->useClientPanel();

        Livewire::actingAs($user)
            ->test(ListProdotti::class)
            ->assertSet('centroCostoId', null)
            ->assertSee('Nessun centro di costo disponibile');
    }

    public function test_utente_non_autenticato_viene_respinto_dal_catalogo(): void
    {
        $this->useClientPanel();

        $this->get(ProdottoResource::getUrl('index'))
            ->assertRedirectToRoute('filament.clienti.auth.login');
    }

    public function test_amministratore_non_puo_usare_il_pannello_riservato_ai_clienti(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::query()->create(['name' => 'admin']));
        $this->useClientPanel();

        $this->actingAs($admin)
            ->get(ProdottoResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_costruzione_delle_voci_non_genera_query_n_plus_one(): void
    {
        $listino = $this->priceList($this->ica, 'ICA Scuole');
        $this->price($listino, $this->reference($this->ica, 'ONE'), 1);
        $this->assign($this->centroCosto, $listino);
        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->service->items($this->centroCosto);
        $singleItemQueryCount = count(DB::getQueryLog());

        foreach (range(2, 10) as $index) {
            $this->price(
                $listino,
                $this->reference($this->ica, "ITEM-{$index}"),
                (float) $index,
            );
        }

        DB::flushQueryLog();
        $this->service->items($this->centroCosto);
        $tenItemsQueryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($singleItemQueryCount, $tenItemsQueryCount);
        $this->assertLessThanOrEqual(6, $tenItemsQueryCount);
    }

    public function test_strutture_e_dati_del_catalogo_legacy_restano_operativi(): void
    {
        $category = Categoria::query()->create(['nome' => 'Categoria legacy']);
        $legacyProduct = Product::query()->create([
            'nome' => 'Prodotto legacy',
            'codice' => 'LEGACY-001',
            'categoria_id' => $category->id,
            'unita_misura' => 'NR',
            'disponibile' => true,
        ]);
        $this->catalogPrice('NEW-001');

        $this->service->items($this->centroCosto);

        $this->assertTrue(Schema::hasTable('Prodotti'));
        $this->assertTrue(Schema::hasTable('listino_prodotto'));
        $this->assertSame('Prodotto legacy', $legacyProduct->refresh()->nome);
    }

    public function test_nuovo_catalogo_non_espone_aggiunta_al_carrello_legacy(): void
    {
        $this->catalogPrice('NO-CART');
        $user = $this->clientUser($this->cliente);
        $this->useClientPanel();

        Livewire::actingAs($user)
            ->test(ListProdotti::class)
            ->assertSee('Ordini in fase di attivazione')
            ->assertDontSee('Aggiungi al carrello');

        $this->assertArrayNotHasKey('view', ProdottoResource::getPages());
    }

    private function client(string $name, string $vatNumber): Cliente
    {
        return Cliente::query()->create([
            'nome' => $name,
            'partita_iva' => $vatNumber,
        ]);
    }

    private function center(Cliente $client, string $name): CentroCosto
    {
        return CentroCosto::query()->create([
            'cliente_id' => $client->id,
            'nome' => $name,
        ]);
    }

    private function supplier(string $code): Fornitore
    {
        return Fornitore::query()->create([
            'code' => $code,
            'nome' => $code,
            'attivo' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function priceList(Fornitore $supplier, string $name, array $attributes = []): Listino
    {
        return Listino::query()->create(array_merge([
            'fornitore_id' => $supplier->id,
            'nome_listino' => $name,
            'tipo' => 'acquisto',
            'attivo' => true,
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function reference(
        Fornitore $supplier,
        string $code,
        array $attributes = [],
    ): ReferenzaFornitore {
        return ReferenzaFornitore::query()->create(array_merge([
            'fornitore_id' => $supplier->id,
            'supplier_code' => $code,
            'descrizione' => "Referenza {$code}",
            'attivo' => true,
            'ordinabile' => true,
            'source_hash' => hash('sha256', $supplier->code.$code),
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function price(
        Listino $listino,
        ReferenzaFornitore $reference,
        float $amount,
        array $attributes = [],
    ): ListinoReferenza {
        return ListinoReferenza::query()->create(array_merge([
            'listino_id' => $listino->id,
            'referenza_fornitore_id' => $reference->id,
            'prezzo' => $amount,
            'attivo' => true,
            'ordinabile' => true,
        ], $attributes));
    }

    private function assign(CentroCosto $center, Listino ...$priceLists): void
    {
        $center->listini()->attach(array_map(
            static fn (Listino $priceList): int => (int) $priceList->getKey(),
            $priceLists,
        ));
    }

    /**
     * @param  array<string, mixed>  $referenceAttributes
     */
    private function catalogPrice(string $code, array $referenceAttributes = []): ListinoReferenza
    {
        $listino = $this->priceList($this->ica, "Listino {$code}");
        $reference = $this->reference($this->ica, $code, $referenceAttributes);
        $price = $this->price($listino, $reference, 4.5, ['price_unit' => 'NR']);
        $this->assign($this->centroCosto, $listino);

        return $price;
    }

    private function clientUser(Cliente $client): User
    {
        $user = User::factory()->create(['cliente_id' => $client->id]);
        $role = Role::query()->firstOrCreate(['name' => 'cliente']);
        $user->assignRole($role);

        return $user;
    }

    private function useClientPanel(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('clienti'));
    }
}
