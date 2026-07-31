<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Client\Pages\Carrello;
use App\Filament\Client\Pages\Ordini as CustomerOrders;
use App\Filament\Client\Resources\Prodotti\Pages\ListProdotti;
use App\Mail\OrderQuoteRequestMail;
use App\Models\CentroCosto;
use App\Models\Cliente;
use App\Models\Fornitore;
use App\Models\Listino;
use App\Models\ListinoReferenza;
use App\Models\Ordine;
use App\Models\ReferenzaFornitore;
use App\Models\User;
use App\Services\Orders\CatalogCartService;
use App\Services\Orders\OrderSubmissionService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class CustomerOrderFlowTest extends TestCase
{
    use RefreshDatabase;

    private Cliente $cliente;

    private CentroCosto $centroCosto;

    private Fornitore $supplier;

    private Listino $listino;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.orders.administration_email' => null]);
        Mail::fake();
        Storage::fake('public');

        $this->cliente = Cliente::query()->create([
            'nome' => 'Cliente ordini',
            'partita_iva' => '12345678901',
        ]);
        $this->centroCosto = CentroCosto::query()->create([
            'cliente_id' => $this->cliente->getKey(),
            'nome' => 'Sede principale',
        ]);
        $this->supplier = Fornitore::query()->create([
            'code' => 'ICA',
            'nome' => 'ICA',
            'email' => null,
            'attivo' => true,
        ]);
        $this->listino = Listino::query()->create([
            'fornitore_id' => $this->supplier->getKey(),
            'nome_listino' => 'ICA Scuole',
            'tipo' => 'acquisto',
            'attivo' => true,
        ]);
        $this->centroCosto->listini()->attach($this->listino->getKey());
        $this->user = User::factory()->create(['cliente_id' => $this->cliente->getKey()]);
        $role = Role::query()->firstOrCreate(['name' => 'cliente']);
        $this->user->assignRole($role);
    }

    public function test_catalogo_carrello_e_checkout_salvano_un_ordine_impegnativo(): void
    {
        $price = $this->catalogPrice('ICA-001', 12.50, priceUnit: 'CT');
        $this->useClientPanel();

        Livewire::actingAs($this->user)
            ->test(ListProdotti::class)
            ->callTableAction('addToCart', $price)
            ->callTableAction('addToCart', $price);

        $contents = app(CatalogCartService::class)->contents($this->user);

        $this->assertSame(2, $contents['items'][(string) $price->getKey()]['quantita']);
        $this->assertSame(25.0, $contents['totale']);

        Livewire::actingAs($this->user)
            ->test(Carrello::class)
            ->set('confirmationNumber', 'PO-2026/001')
            ->set('notes', 'Consegna al piano terra')
            ->call('proceed');

        $ordine = Ordine::query()->with('items')->sole();
        $item = $ordine->items->sole();

        $this->assertSame($this->centroCosto->getKey(), $ordine->centro_costo_id);
        $this->assertSame($this->supplier->getKey(), $ordine->fornitore_id);
        $this->assertSame('Cliente ordini', $ordine->cliente_nome);
        $this->assertSame('12345678901', $ordine->cliente_partita_iva);
        $this->assertSame('Sede principale', $ordine->centro_costo_nome);
        $this->assertSame('ICA', $ordine->fornitore_code);
        $this->assertSame('inviato', $ordine->stato);
        $this->assertSame('in_attesa', $ordine->email_stato);
        $this->assertSame([], $ordine->email_recipients);
        $this->assertSame('25.00', $ordine->totale_lordo);
        $this->assertNull($ordine->totale_netto);
        $this->assertNull($ordine->iva_totale);
        $this->assertSame('ICA-001', $item->supplier_code);
        $this->assertSame('CT', $item->unita);
        $this->assertSame(2, $item->quantita);
        $this->assertSame('12.5000', $item->prezzo_unitario_lordo);
        $this->assertNull($item->prodotto_id);
        $this->assertSame('Consegna al piano terra', $ordine->note);
        $this->assertFalse(session()->has(CatalogCartService::SESSION_KEY));
        Storage::disk('public')->assertExists((string) $ordine->pdf_path);
        Mail::assertNothingSent();
    }

    public function test_prezzo_e_disponibilita_vengono_riletti_al_checkout(): void
    {
        $price = $this->catalogPrice('ICA-LIVE', 5);
        $cart = app(CatalogCartService::class);
        $cart->add($this->user, (int) $this->centroCosto->getKey(), (int) $price->getKey());
        $captured = $cart->contents($this->user);
        $price->update(['prezzo' => 7.25]);

        $ordine = app(OrderSubmissionService::class)->submit(
            $this->user,
            (int) $this->centroCosto->getKey(),
            $captured['items'],
            'PRICE-UPDATED',
        );

        $this->assertSame('7.2500', $ordine->items->sole()->prezzo_unitario_lordo);

        $otherPrice = $this->catalogPrice('ICA-REMOVED', 3);
        $payload = [[
            'listino_referenza_id' => $otherPrice->getKey(),
            'quantita' => 1,
        ]];
        $otherPrice->update(['attivo' => false]);

        try {
            app(OrderSubmissionService::class)->submit(
                $this->user,
                (int) $this->centroCosto->getKey(),
                $payload,
                'REMOVED',
            );
            $this->fail('Una referenza non disponibile non deve essere ordinata.');
        } catch (ValidationException) {
            $this->assertDatabaseMissing('ordini', ['riferimento_cliente' => 'REMOVED']);
        }
    }

    public function test_carrello_non_puo_mescolare_centri_di_costo(): void
    {
        $price = $this->catalogPrice('ICA-CENTER', 5);
        $secondCenter = CentroCosto::query()->create([
            'cliente_id' => $this->cliente->getKey(),
            'nome' => 'Seconda sede',
        ]);
        $secondCenter->listini()->attach($this->listino->getKey());
        $cart = app(CatalogCartService::class);
        $cart->add($this->user, (int) $this->centroCosto->getKey(), (int) $price->getKey());

        $this->expectException(ValidationException::class);

        $cart->add($this->user, (int) $secondCenter->getKey(), (int) $price->getKey());
    }

    public function test_carrello_non_piu_valido_viene_svuotato_con_un_avviso(): void
    {
        $price = $this->catalogPrice('ICA-STALE', 5);
        app(CatalogCartService::class)->add(
            $this->user,
            (int) $this->centroCosto->getKey(),
            (int) $price->getKey(),
        );
        $price->update(['attivo' => false]);
        $this->useClientPanel();

        Livewire::actingAs($this->user)
            ->test(Carrello::class)
            ->assertSee('Il carrello è vuoto');

        $this->assertFalse(session()->has(CatalogCartService::SESSION_KEY));
    }

    public function test_customer_article_code_duplicato_non_consolida_le_righe(): void
    {
        $first = $this->catalogPrice('ICA-A', 2, customerCode: 'CODICE-CONDIVISO');
        $second = $this->catalogPrice('ICA-B', 3, customerCode: 'CODICE-CONDIVISO');
        $cart = app(CatalogCartService::class);
        $cart->add($this->user, (int) $this->centroCosto->getKey(), (int) $first->getKey());
        $cart->add($this->user, (int) $this->centroCosto->getKey(), (int) $second->getKey());
        $contents = $cart->contents($this->user);

        $ordine = app(OrderSubmissionService::class)->submit(
            $this->user,
            $contents['centro_costo_id'],
            $contents['items'],
            'DUPLICATE-CUSTOMER-CODE',
        );

        $this->assertCount(2, $ordine->items);
        $this->assertSame(
            ['ICA-A', 'ICA-B'],
            $ordine->items->pluck('supplier_code')->sort()->values()->all(),
        );
    }

    public function test_email_solo_amministrazione_risulta_parziale(): void
    {
        config(['services.orders.administration_email' => 'amministrazione@example.test']);
        $price = $this->catalogPrice('ICA-MAIL', 10);

        $ordine = $this->submitPrice($price, 'ADMIN-ONLY');

        $this->assertSame('parziale', $ordine->email_stato);
        $this->assertNotNull($ordine->email_sent_at);
        $this->assertSame(['amministrazione@example.test'], $ordine->email_recipients);
        Mail::assertSent(
            OrderQuoteRequestMail::class,
            static fn (OrderQuoteRequestMail $mail): bool => $mail->hasTo('amministrazione@example.test'),
        );
    }

    public function test_email_fornitore_e_amministrazione_risulta_completa(): void
    {
        config(['services.orders.administration_email' => 'amministrazione@example.test']);
        $this->supplier->update(['email' => 'ordini.ica@example.test']);
        $price = $this->catalogPrice('ICA-FULL-MAIL', 10);

        $ordine = $this->submitPrice($price, 'FULL-MAIL');

        $this->assertSame('inviata', $ordine->email_stato);
        Mail::assertSent(
            OrderQuoteRequestMail::class,
            static fn (OrderQuoteRequestMail $mail): bool => $mail->hasTo('ordini.ica@example.test')
                && $mail->hasCc('amministrazione@example.test'),
        );
    }

    public function test_numero_ordine_non_puo_essere_riutilizzato_e_storico_e_isolato(): void
    {
        $price = $this->catalogPrice('ICA-HISTORY', 4);
        $this->submitPrice($price, 'PO-SHARED');

        try {
            $this->submitPrice($price, 'PO-SHARED');
            $this->fail('Il numero ordine duplicato doveva essere rifiutato.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('ordini', 1);
        }

        $otherClient = Cliente::query()->create([
            'nome' => 'Altro cliente',
            'partita_iva' => '99999999999',
        ]);
        $otherUser = User::factory()->create(['cliente_id' => $otherClient->getKey()]);
        Ordine::query()->create([
            'user_id' => $otherUser->getKey(),
            'stato' => 'inviato',
            'riferimento_cliente' => 'ORDER-OTHER-CLIENT',
            'totale_lordo' => 1,
        ]);
        $this->useClientPanel();

        Livewire::actingAs($this->user)
            ->test(CustomerOrders::class)
            ->assertSee('PO-SHARED')
            ->assertDontSee('ORDER-OTHER-CLIENT');
    }

    private function submitPrice(ListinoReferenza $price, string $reference): Ordine
    {
        $cart = app(CatalogCartService::class);
        $cart->clear();
        $cart->add($this->user, (int) $this->centroCosto->getKey(), (int) $price->getKey());
        $contents = $cart->contents($this->user);

        return app(OrderSubmissionService::class)->submit(
            $this->user,
            $contents['centro_costo_id'],
            $contents['items'],
            $reference,
        );
    }

    private function catalogPrice(
        string $supplierCode,
        float $amount,
        ?string $customerCode = null,
        ?string $priceUnit = 'NR',
    ): ListinoReferenza {
        $reference = ReferenzaFornitore::query()->create([
            'fornitore_id' => $this->supplier->getKey(),
            'supplier_code' => $supplierCode,
            'customer_article_code' => $customerCode,
            'descrizione' => "Articolo {$supplierCode}",
            'sales_unit' => $priceUnit,
            'attivo' => true,
            'ordinabile' => true,
            'source_hash' => hash('sha256', $supplierCode),
        ]);

        return ListinoReferenza::query()->create([
            'listino_id' => $this->listino->getKey(),
            'referenza_fornitore_id' => $reference->getKey(),
            'prezzo' => $amount,
            'price_unit' => $priceUnit,
            'iva_percentuale' => null,
            'attivo' => true,
            'ordinabile' => true,
        ]);
    }

    private function useClientPanel(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('clienti'));
    }
}
