<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Client\Pages\Carrello;
use App\Filament\Client\Pages\Ordini as CustomerOrders;
use App\Filament\Client\Resources\Prodotti\Pages\ListProdotti;
use App\Filament\Resources\OrdineResource\Pages\ViewOrdine;
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
use App\Services\Orders\OrderNotificationService;
use App\Services\Orders\OrderStatusService;
use App\Services\Orders\OrderSubmissionService;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;
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
        Storage::fake('local');

        $this->cliente = Cliente::query()->create([
            'nome' => 'Cliente ordini',
            'partita_iva' => '12345678901',
        ]);
        $this->centroCosto = CentroCosto::query()->create([
            'cliente_id' => $this->cliente->getKey(),
            'nome' => 'Sede principale',
            'indirizzo' => 'Via Centro 10, Milano',
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
            ->assertSet('destinationAddress', 'Via Centro 10, Milano')
            ->set('confirmationNumber', 'PO-2026/001')
            ->set('priority', Ordine::PRIORITY_URGENT)
            ->set('destinationAddress', 'Via Consegna 20, Milano')
            ->set('requesterReference', 'Mario Rossi')
            ->set('deliveryHours', 'Lunedi-venerdi 08:00-12:00')
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
        $this->assertSame(Ordine::STATUS_NEW, $ordine->stato);
        $this->assertNotNull($ordine->data_ordine);
        $this->assertSame($this->user->name, $ordine->inviato_da_nome);
        $this->assertSame($this->user->email, $ordine->inviato_da_email);
        $this->assertSame(Ordine::PRIORITY_URGENT, $ordine->priorita);
        $this->assertSame('Via Consegna 20, Milano', $ordine->indirizzo_destinazione);
        $this->assertSame('Mario Rossi', $ordine->riferimento_richiedente);
        $this->assertSame('Lunedi-venerdi 08:00-12:00', $ordine->orari_consegna);
        $this->assertSame('errore', $ordine->email_stato);
        $this->assertSame([], $ordine->email_recipients);
        $this->assertSame(1, $ordine->email_attempts);
        $this->assertNotNull($ordine->email_last_attempt_at);
        $this->assertSame(
            'Destinatario amministrativo ORDER_ADMINISTRATION_EMAIL non configurato.',
            $ordine->email_last_error,
        );
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
        Storage::disk('local')->assertExists((string) $ordine->pdf_path);
        Storage::disk('public')->assertMissing((string) $ordine->pdf_path);
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
            'indirizzo' => 'Via Seconda 2, Milano',
        ]);
        $secondCenter->listini()->attach($this->listino->getKey());
        $cart = app(CatalogCartService::class);
        $cart->add($this->user, (int) $this->centroCosto->getKey(), (int) $price->getKey());

        $this->expectException(ValidationException::class);

        $cart->add($this->user, (int) $secondCenter->getKey(), (int) $price->getKey());
    }

    public function test_indirizzo_di_destinazione_e_obbligatorio_se_il_centro_non_lo_propone(): void
    {
        $this->centroCosto->update(['indirizzo' => null]);
        $price = $this->catalogPrice('ICA-NO-ADDRESS', 5);
        app(CatalogCartService::class)->add(
            $this->user,
            (int) $this->centroCosto->getKey(),
            (int) $price->getKey(),
        );
        $this->useClientPanel();

        Livewire::actingAs($this->user)
            ->test(Carrello::class)
            ->assertSet('destinationAddress', '')
            ->set('confirmationNumber', 'NO-ADDRESS')
            ->call('proceed')
            ->assertHasErrors(['destinationAddress' => 'required']);

        $this->assertDatabaseMissing('ordini', ['riferimento_cliente' => 'NO-ADDRESS']);
    }

    public function test_solo_un_amministratore_puo_segnare_un_ordine_come_evaso(): void
    {
        $ordine = $this->submitPrice($this->catalogPrice('ICA-STATUS', 5), 'STATUS-1');
        $service = app(OrderStatusService::class);

        try {
            $service->markAsFulfilled($ordine, $this->user);
            $this->fail('Un cliente non deve poter evadere un ordine.');
        } catch (AuthorizationException) {
            $this->assertSame(Ordine::STATUS_NEW, $ordine->fresh()->stato);
        }

        $admin = User::factory()->create();
        $admin->assignRole(Role::query()->create(['name' => 'admin']));
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($admin)
            ->test(ViewOrdine::class, ['record' => $ordine->getRouteKey()])
            ->callAction('markAsFulfilled')
            ->assertHasNoActionErrors();

        $this->assertSame(Ordine::STATUS_FULFILLED, $ordine->fresh()->stato);
    }

    public function test_migration_ordini_definisce_lo_schema_senza_convertire_dati(): void
    {
        $migration = require database_path(
            'migrations/2026_08_06_000001_extend_order_details_and_statuses.php'
        );

        $migration->down();

        $this->assertFalse(Schema::hasColumn('ordini', 'data_ordine'));

        $migration->up();

        $ordine = Ordine::query()->create([
            'user_id' => $this->user->getKey(),
            'riferimento_cliente' => 'MIGRATION-STATUS',
            'totale_lordo' => 1,
        ])->refresh();
        $this->assertSame(Ordine::STATUS_NEW, $ordine->stato);
        $this->assertNull($ordine->data_ordine);
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
        config(['services.orders.administration_email' => 'ordini@enjoy-service.it']);
        $price = $this->catalogPrice('ICA-MAIL', 10);

        $ordine = $this->submitPrice($price, 'ADMIN-ONLY');

        $this->assertSame('parziale', $ordine->email_stato);
        $this->assertNotNull($ordine->email_sent_at);
        $this->assertSame(['ordini@enjoy-service.it'], $ordine->email_recipients);
        $this->assertSame(1, $ordine->email_attempts);
        $this->assertNull($ordine->email_last_error);
        Mail::assertSent(
            OrderQuoteRequestMail::class,
            static fn (OrderQuoteRequestMail $mail): bool => $mail->hasTo('ordini@enjoy-service.it'),
        );
    }

    public function test_email_fornitore_e_amministrazione_risulta_completa(): void
    {
        config(['services.orders.administration_email' => 'ordini@enjoy-service.it']);
        $this->supplier->update(['email' => 'ordini.ica@example.test']);
        $price = $this->catalogPrice('ICA-FULL-MAIL', 10);

        $ordine = $this->submitPrice($price, 'FULL-MAIL');

        $this->assertSame('inviata', $ordine->email_stato);
        Mail::assertSent(
            OrderQuoteRequestMail::class,
            static fn (OrderQuoteRequestMail $mail): bool => $mail->hasTo('ordini.ica@example.test')
                && $mail->hasCc('ordini@enjoy-service.it'),
        );
    }

    public function test_email_ordine_urgente_espone_priorita_e_dati_di_consegna(): void
    {
        config(['services.orders.administration_email' => 'ordini@enjoy-service.it']);
        $price = $this->catalogPrice('ICA-URGENT', 10);
        $cart = app(CatalogCartService::class);
        $cart->add($this->user, (int) $this->centroCosto->getKey(), (int) $price->getKey());
        $contents = $cart->contents($this->user);

        app(OrderSubmissionService::class)->submit(
            $this->user,
            $contents['centro_costo_id'],
            $contents['items'],
            'URGENT-1',
            priority: Ordine::PRIORITY_URGENT,
            destinationAddress: 'Via Urgente 1, Milano',
            requesterReference: 'Portineria',
            deliveryHours: '08:00-10:00',
        );

        Mail::assertSent(
            OrderQuoteRequestMail::class,
            static function (OrderQuoteRequestMail $mail): bool {
                $mail->assertSeeInHtml('Urgente');
                $mail->assertSeeInHtml('Via Urgente 1, Milano');
                $mail->assertSeeInHtml('Portineria');
                $mail->assertSeeInHtml('08:00-10:00');

                return str_starts_with($mail->envelope()->subject, '[URGENTE] ');
            },
        );
    }

    public function test_errore_del_trasporto_email_viene_registrato_senza_perdere_l_ordine(): void
    {
        config(['services.orders.administration_email' => 'ordini@enjoy-service.it']);
        Mail::shouldReceive('to')
            ->once()
            ->andThrow(new RuntimeException('SMTP non disponibile'));

        $ordine = $this->submitPrice($this->catalogPrice('ICA-MAIL-ERROR', 10), 'MAIL-ERROR');

        $this->assertSame('errore', $ordine->email_stato);
        $this->assertSame(1, $ordine->email_attempts);
        $this->assertSame('SMTP non disponibile', $ordine->email_last_error);
        $this->assertNull($ordine->email_sent_at);
        $this->assertDatabaseHas('ordini', ['riferimento_cliente' => 'MAIL-ERROR']);
    }

    public function test_amministratore_puo_reinviare_un_email_fallita_e_l_esito_e_tracciato(): void
    {
        $ordine = $this->submitPrice($this->catalogPrice('ICA-RESEND', 10), 'RESEND-1');
        $this->assertSame('errore', $ordine->email_stato);
        $this->assertSame(1, $ordine->email_attempts);

        config(['services.orders.administration_email' => 'ordini@enjoy-service.it']);
        $admin = User::factory()->create();
        $admin->assignRole(Role::query()->create(['name' => 'admin']));
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($admin)
            ->test(ViewOrdine::class, ['record' => $ordine->getRouteKey()])
            ->callAction('resendEmail')
            ->assertHasNoActionErrors();

        $ordine->refresh();
        $this->assertSame('parziale', $ordine->email_stato);
        $this->assertSame(2, $ordine->email_attempts);
        $this->assertNull($ordine->email_last_error);
        $this->assertNotNull($ordine->email_sent_at);
        Mail::assertSent(
            OrderQuoteRequestMail::class,
            static function (OrderQuoteRequestMail $mail): bool {
                $mail->assertSeeInHtml('Via Centro 10, Milano');
                $mail->assertSeeInHtml('Standard');

                return $mail->hasTo('ordini@enjoy-service.it');
            },
        );
    }

    public function test_cliente_non_puo_reinviare_email_ordine(): void
    {
        $ordine = $this->submitPrice($this->catalogPrice('ICA-NO-RESEND', 10), 'NO-RESEND');

        $this->expectException(AuthorizationException::class);

        app(OrderNotificationService::class)->resend($ordine, $this->user);
    }

    public function test_migration_tracciamento_email_supporta_rollback_e_riesecuzione(): void
    {
        $migration = require database_path(
            'migrations/2026_08_06_000002_add_email_delivery_tracking_to_orders.php'
        );

        $migration->down();
        $this->assertFalse(Schema::hasColumn('ordini', 'email_attempts'));

        $migration->up();
        $this->assertTrue(Schema::hasColumn('ordini', 'email_attempts'));
    }

    public function test_documenti_privati_sono_scaricabili_solo_da_cliente_proprietario_e_admin(): void
    {
        $ordine = $this->submitPrice($this->catalogPrice('ICA-DOCUMENT', 10), 'DOCUMENT-1');

        $pdfResponse = $this->actingAs($this->user)->get(route('orders.documents.download', [
            'ordine' => $ordine,
            'format' => 'pdf',
        ]));
        $pdfResponse
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
        $cacheControl = (string) $pdfResponse->headers->get('cache-control');
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringStartsWith('%PDF-', $pdfResponse->getContent());

        $ordine->forceFill(['riferimento_richiedente' => '=1+1'])->save();
        $ordine->items()->firstOrFail()->forceFill(['descrizione' => '=HYPERLINK("https://example.test")'])->save();

        $xlsxResponse = $this->get(route('orders.documents.download', [
            'ordine' => $ordine,
            'format' => 'xlsx',
        ]));
        $xlsxResponse
            ->assertOk()
            ->assertHeader(
                'content-type',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            );

        $ordine->refresh();
        Storage::disk('local')->assertExists((string) $ordine->xlsx_path);
        Storage::disk('public')->assertMissing((string) $ordine->xlsx_path);
        $spreadsheet = IOFactory::load(Storage::disk('local')->path((string) $ordine->xlsx_path));
        $sheet = $spreadsheet->getActiveSheet();
        $this->assertSame('Ordine cliente', $sheet->getCell('A1')->getValue());
        $this->assertSame('Standard', $sheet->getCell('B6')->getValue());
        $this->assertSame('ICA-DOCUMENT', $sheet->getCell('A18')->getValue());
        $this->assertSame('=1+1', $sheet->getCell('B13')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('B13')->getDataType());
        $this->assertSame('=HYPERLINK("https://example.test")', $sheet->getCell('B18')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('B18')->getDataType());
        $spreadsheet->disconnectWorksheets();

        $otherClient = Cliente::query()->create([
            'nome' => 'Cliente documenti non autorizzato',
            'partita_iva' => '88888888888',
        ]);
        $otherUser = User::factory()->create(['cliente_id' => $otherClient->getKey()]);
        $otherUser->assignRole(Role::query()->where('name', 'cliente')->firstOrFail());
        $this->actingAs($otherUser)
            ->get(route('orders.documents.download', ['ordine' => $ordine, 'format' => 'pdf']))
            ->assertForbidden();

        $admin = User::factory()->create();
        $admin->assignRole(Role::query()->create(['name' => 'admin']));
        $this->actingAs($admin)
            ->get(route('orders.documents.download', ['ordine' => $ordine, 'format' => 'xlsx']))
            ->assertOk();

        auth()->logout();
        $this->get(route('orders.documents.download', ['ordine' => $ordine, 'format' => 'pdf']))
            ->assertForbidden();
    }

    public function test_migration_documenti_e_esclusivamente_strutturale(): void
    {
        $ordine = Ordine::query()->create([
            'user_id' => $this->user->getKey(),
            'centro_costo_id' => $this->centroCosto->getKey(),
            'stato' => Ordine::STATUS_NEW,
            'riferimento_cliente' => 'PRIVATE-MIGRATION',
            'totale_lordo' => 1,
            'pdf_path' => 'ordini/legacy/ordine.pdf',
        ]);
        Storage::disk('public')->put((string) $ordine->pdf_path, 'legacy-pdf');
        $migration = require database_path(
            'migrations/2026_08_06_000003_store_order_documents_privately.php'
        );

        $migration->down();
        $migration->up();

        $this->assertTrue(Schema::hasColumn('ordini', 'xlsx_path'));
        Storage::disk('public')->assertExists((string) $ordine->pdf_path);
        Storage::disk('local')->assertMissing((string) $ordine->pdf_path);
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
