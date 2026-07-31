<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\ImportaCatalogo;
use App\Models\CategoriaCatalogo;
use App\Models\Fornitore;
use App\Models\ImportBatch;
use App\Models\Listino;
use App\Models\ListinoReferenza;
use App\Models\ReferenzaFornitore;
use App\Models\User;
use App\Services\Imports\Catalog\CatalogPersistentImportService;
use App\Services\Imports\Catalog\Exceptions\CatalogImportConflictException;
use Database\Seeders\SupplierSeeder;
use DomainException;
use Filament\Forms\Components\FileUpload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use ReflectionClass;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class CatalogPersistentImportTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SupplierSeeder::class);
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    public function test_ica_import_creates_catalog_list_prices_packaging_and_only_exact_image(): void
    {
        $batch = $this->service()->import(
            'ICA',
            'Scuole',
            $this->icaFile(),
            'scuole'
        );

        $this->assertSame(ImportBatch::STATUS_COMPLETED, $batch->stato);
        $this->assertSame(2, $batch->referenze_create);
        $this->assertSame(2, $batch->prezzi_creati);
        $this->assertDatabaseCount('referenze_fornitore', 2);
        $this->assertDatabaseCount('listino_referenze', 2);
        $this->assertDatabaseHas('Listini', [
            'fornitore_id' => $this->supplier('ICA')->id,
            'nome_listino' => 'Scuole',
            'tipo' => 'acquisto',
        ]);
        $this->assertDatabaseHas('listino_referenze', [
            'prezzo' => 4.5,
            'price_unit' => 'NR',
        ]);
        $this->assertDatabaseHas('referenza_packagings', [
            'unita_contenitore' => 'CT',
            'unita_contenuta' => 'NR',
            'quantita' => 6,
        ]);

        $reference = ReferenzaFornitore::query()
            ->where('supplier_code', 'ICA-1')
            ->firstOrFail();

        $this->assertNotNull($reference->immagine_path);
        $this->assertFalse(str_starts_with($reference->immagine_path, '/'));
        $this->assertStringStartsWith('cataloghi/ica/', $reference->immagine_path);
        $this->assertStringContainsString(
            $reference->immagine_path,
            $reference->immagine_url
        );
        Storage::disk('public')->assertExists($reference->immagine_path);
        $this->assertCount(1, Storage::disk('public')->allFiles('cataloghi/ica'));
        $this->assertSame(
            1,
            $batch->riepilogo['summary']['images_unassociated']
        );
    }

    public function test_same_ica_file_is_idempotent_but_keeps_batch_history(): void
    {
        $file = $this->icaFile();

        $first = $this->service()->import('ICA', 'Scuole', $file, 'scuole');
        $second = $this->service()->import('ICA', 'Scuole', $file, 'scuole');

        $this->assertDatabaseCount('referenze_fornitore', 2);
        $this->assertDatabaseCount('Listini', 1);
        $this->assertDatabaseCount('listino_referenze', 2);
        $this->assertDatabaseCount('referenza_packagings', 1);
        $this->assertDatabaseCount('import_batches', 2);
        $this->assertDatabaseCount('categorie_catalogo', 1);
        $this->assertDatabaseCount('referenza_fornitore_categoria', 2);
        $this->assertCount(1, Storage::disk('public')->allFiles('cataloghi/ica'));
        $this->assertSame(0, $second->referenze_create);
        $this->assertSame(0, $second->referenze_aggiornate);
        $this->assertSame(0, $second->prezzi_creati);
        $this->assertSame(0, $second->prezzi_aggiornati);
        $this->assertSame([
            'categorie_create' => 1,
            'referenze_collegate' => 2,
            'referenze_senza_categoria' => 0,
            'categorie_per_fornitore' => ['ICA' => 1],
        ], $first->riepilogo['categories']);
        $this->assertSame([
            'categorie_create' => 0,
            'referenze_collegate' => 2,
            'referenze_senza_categoria' => 0,
            'categorie_per_fornitore' => ['ICA' => 1],
        ], $second->riepilogo['categories']);
    }

    public function test_import_creates_category_and_links_it_to_reference(): void
    {
        $this->service()->import('ICA', 'Scuole', $this->icaFile([
            ['10 Detergenti', 'ICA-CAT', 'Prodotto classificato', 4.5, 'NR', null],
        ], false), 'scuole');

        $category = CategoriaCatalogo::query()->firstOrFail();
        $reference = ReferenzaFornitore::query()->firstOrFail();

        $this->assertSame('10', $category->codice);
        $this->assertSame('Detergenti', $category->nome);
        $this->assertTrue($reference->categorie()->whereKey($category->id)->exists());
    }

    public function test_same_category_codes_are_separated_by_supplier(): void
    {
        $this->service()->import('ICA', 'ICA', $this->icaFile([
            ['10 Detergenti', 'ICA-1', 'Prodotto ICA', 4.5, 'NR', null],
        ], false), 'scuole');
        $this->service()->import('IGROUP', 'IGROUP', $this->igroupCategoryFile([
            [1, 'IG-1', 'Prodotto IGROUP', 9, '10', 'Detergenti'],
        ]));

        $this->assertDatabaseCount('categorie_catalogo', 2);
        $this->assertSame(
            ['ICA', 'IGROUP'],
            CategoriaCatalogo::query()->with('fornitore')->get()->pluck('fornitore.code')->sort()->values()->all(),
        );
    }

    public function test_category_name_is_updated_by_stable_source_code(): void
    {
        $this->service()->import('IGROUP', 'Listino', $this->igroupCategoryFile([
            [1, 'IG-1', 'Prodotto', 9, '10', 'Detergenti'],
        ]));
        $this->service()->import('IGROUP', 'Listino', $this->igroupCategoryFile([
            [1, 'IG-1', 'Prodotto', 9, '10', 'Detergenza professionale'],
        ]));
        $this->service()->import('IGROUP', 'Listino', $this->igroupCategoryFile([
            [1, 'IG-1', 'Prodotto', 9, '11', 'Detergenza professionale'],
        ]));

        $this->assertDatabaseCount('categorie_catalogo', 1);
        $this->assertDatabaseHas('categorie_catalogo', [
            'codice' => '11',
            'nome' => 'Detergenza professionale',
        ]);
    }

    public function test_parent_category_is_created_and_linked_to_main_level(): void
    {
        $this->service()->import('IGROUP', 'Listino', $this->igroupCategoryFile([
            [1, 'IG-1', 'Prodotto', 9, '10', 'Detergenti', '1', 'Pulizia'],
        ]));

        $parent = CategoriaCatalogo::query()->where('codice', '1')->firstOrFail();
        $leaf = CategoriaCatalogo::query()->where('codice', '10')->firstOrFail();

        $this->assertSame($parent->id, $leaf->parent_id);
        $this->assertSame('Pulizia', $leaf->parent->nome);
    }

    public function test_missing_category_does_not_remove_existing_link_or_reference(): void
    {
        $this->service()->import('IGROUP', 'Listino', $this->igroupCategoryFile([
            [1, 'IG-1', 'Prodotto', 9, '10', 'Detergenti'],
        ]));
        $this->service()->import('IGROUP', 'Listino', $this->igroupCategoryFile([
            [1, 'IG-1', 'Prodotto', 9, null, null],
            [2, 'IG-2', 'Senza categoria', 5, null, null],
        ]));

        $this->assertDatabaseCount('referenze_fornitore', 2);
        $this->assertDatabaseCount('categorie_catalogo', 1);
        $this->assertDatabaseCount('referenza_fornitore_categoria', 1);
        $this->assertSame(1, ReferenzaFornitore::query()->where('supplier_code', 'IG-1')->firstOrFail()->categorie()->count());
        $this->assertSame(0, ReferenzaFornitore::query()->where('supplier_code', 'IG-2')->firstOrFail()->categorie()->count());
    }

    public function test_category_is_not_invented_when_original_data_is_unavailable(): void
    {
        $this->service()->import('IGROUP', 'Listino', $this->igroupCategoryFile([
            [1, 'IG-1', 'Prodotto', 9, null, null],
        ]));

        $this->assertDatabaseCount('referenze_fornitore', 1);
        $this->assertDatabaseCount('categorie_catalogo', 0);
        $this->assertDatabaseCount('referenza_fornitore_categoria', 0);
    }

    public function test_same_igroup_file_is_idempotent_and_does_not_persist_ica_profile(): void
    {
        $file = $this->igroupFile($this->igroupRows());

        $this->service()->import('IGROUP', 'Scuole', $file, 'scuole');
        $second = $this->service()->import('IGROUP', 'Scuole', $file, 'scuole');

        $this->assertDatabaseCount('referenze_fornitore', 5);
        $this->assertDatabaseCount('Listini', 1);
        $this->assertDatabaseCount('listino_referenze', 5);
        $this->assertDatabaseCount('import_batches', 2);
        $this->assertNull($second->profilo);
        $this->assertSame(0, $second->referenze_create);
        $this->assertSame(0, $second->referenze_aggiornate);
        $this->assertSame(0, $second->prezzi_creati);
        $this->assertSame(0, $second->prezzi_aggiornati);
        $this->assertSame(1, $second->righe_ignorate);
    }

    public function test_reference_schema_does_not_store_list_price(): void
    {
        $this->assertFalse(Schema::hasColumn('referenze_fornitore', 'prezzo'));
        $this->assertTrue(Schema::hasColumn('listino_referenze', 'prezzo'));
        $this->assertTrue(Schema::hasColumn('listino_referenze', 'prezzo_sorgente'));
        $this->assertFalse(Schema::hasColumn('listino_referenze', 'fornitore_id'));
    }

    public function test_new_import_updates_price_without_deleting_missing_references(): void
    {
        $this->service()->import('ICA', 'Scuole', $this->icaFile(), 'scuole');
        $updated = $this->service()->import(
            'ICA',
            'Scuole',
            $this->icaFile([
                ['Detergenti', 'ICA-1', 'Prodotto ICA uno', 5.25, 'NR', '6 pezzi per cartone'],
            ], false),
            'scuole'
        );

        $this->assertDatabaseCount('referenze_fornitore', 2);
        $this->assertDatabaseCount('listino_referenze', 2);
        $this->assertDatabaseHas('referenze_fornitore', ['supplier_code' => 'ICA-2']);
        $this->assertDatabaseHas('listino_referenze', ['prezzo' => 5.25]);
        $this->assertSame(1, $updated->prezzi_aggiornati);
    }

    public function test_same_reference_can_have_different_prices_in_two_supplier_lists(): void
    {
        $this->service()->import(
            'ICA',
            'Scuole',
            $this->icaFile([
                ['Detergenti', 'ICA-1', 'Prodotto ICA uno', 4.5, 'NR', null],
            ], false),
            'scuole'
        );
        $this->service()->import(
            'ICA',
            'Altro',
            $this->icaFile([
                ['Detergenti', 'ICA-1', 'Prodotto ICA uno', 7.1, 'NR', null],
            ], false),
            'scuole'
        );

        $reference = ReferenzaFornitore::query()->where('supplier_code', 'ICA-1')->firstOrFail();
        $prices = ListinoReferenza::query()
            ->where('referenza_fornitore_id', $reference->id)
            ->orderBy('prezzo')
            ->pluck('prezzo')
            ->all();

        $this->assertDatabaseCount('referenze_fornitore', 1);
        $this->assertDatabaseCount('Listini', 2);
        $this->assertSame(['4.50000', '7.10000'], $prices);
    }

    public function test_manual_price_override_is_not_replaced_by_later_import(): void
    {
        $this->service()->import(
            'ICA',
            'Scuole',
            $this->icaFile([
                ['Detergenti', 'ICA-1', 'Prodotto ICA uno', 4.5, 'NR', null],
            ], false),
            'scuole'
        );
        $price = ListinoReferenza::query()->firstOrFail();
        $price->update([
            'prezzo' => 8.75,
            'modificato_manualmente' => true,
        ]);

        $this->service()->import(
            'ICA',
            'Scuole',
            $this->icaFile([
                ['Detergenti', 'ICA-1', 'Prodotto ICA uno', 5.5, 'NR', null],
            ], false),
            'scuole'
        );

        $price->refresh();
        $this->assertSame('8.75000', $price->prezzo);
        $this->assertSame('5.50000', $price->prezzo_sorgente);
        $this->assertTrue($price->modificato_manualmente);
    }

    public function test_igroup_import_classifies_duplicates_and_ambiguities_without_code_replacement(): void
    {
        $batch = $this->service()->import(
            'IGROUP',
            'Scuole',
            $this->igroupFile($this->igroupRows())
        );

        $this->assertSame(ImportBatch::STATUS_COMPLETED, $batch->stato);
        $this->assertSame(5, $batch->referenze_create);
        $this->assertSame(5, $batch->prezzi_creati);
        $this->assertSame(1, $batch->righe_ignorate);
        $this->assertNotEmpty($batch->warnings);
        $this->assertSame([], $batch->errori);
        $this->assertDatabaseCount('referenze_fornitore', 5);
        $this->assertDatabaseCount('listino_referenze', 5);
        $this->assertDatabaseHas('referenze_fornitore', [
            'supplier_code' => 'NEW',
            'customer_article_code' => 'OLD',
        ]);
        $this->assertDatabaseHas('referenze_fornitore', [
            'supplier_code' => 'OLD',
            'customer_article_code' => 'OLD',
        ]);
        $this->assertSame(
            2,
            ReferenzaFornitore::query()
                ->where('customer_article_code', 'SHARED')
                ->count()
        );

        $duplicate = collect(
            $batch->riepilogo['diagnostics']['duplicate_supplier_codes']
        )->firstWhere('code', 'DUP');
        $conflictTypes = collect($batch->riepilogo['diagnostics']['conflicts'])
            ->pluck('type')
            ->all();

        $this->assertSame('identical', $duplicate['kind']);
        $this->assertContains('ambiguous_code_change', $conflictTypes);
        $this->assertContains('conflicting_customer_code_duplicate', $conflictTypes);
    }

    public function test_conflicting_supplier_duplicate_fails_and_rolls_back_all_catalog_writes(): void
    {
        $file = $this->igroupFile([
            [1, '2026-01-01', '2026-12-31', 'CONFLICT', 'Descrizione A', 'NR', 10, 9, 'CONFLICT', 'NR', 1],
            [2, '2026-01-01', '2026-12-31', 'CONFLICT', 'Descrizione B', 'NR', 11, 8, 'CONFLICT', 'NR', 1],
        ]);

        try {
            $this->service()->import('IGROUP', 'Conflitto', $file);
            $this->fail('Il conflitto sul codice fornitore doveva bloccare l importazione.');
        } catch (CatalogImportConflictException $exception) {
            $this->assertSame(
                'conflicting_supplier_code_duplicate',
                $exception->conflicts[0]['type']
            );
        }

        $this->assertDatabaseCount('Listini', 0);
        $this->assertDatabaseCount('referenze_fornitore', 0);
        $this->assertDatabaseCount('listino_referenze', 0);
        $batch = ImportBatch::query()->firstOrFail();
        $this->assertSame(ImportBatch::STATUS_FAILED, $batch->stato);
        $this->assertNull($batch->listino_id);
        $this->assertSame(2, $batch->righe_lette);
        $this->assertSame(
            'conflicting_supplier_code_duplicate',
            $batch->riepilogo['diagnostics']['conflicts'][0]['type']
        );
    }

    public function test_parser_failure_is_recorded_in_batch_without_catalog_writes(): void
    {
        try {
            $this->service()->import(
                'ICA',
                'Ristorazione',
                $this->icaFile(withImages: false),
                'ristorazione'
            );
            $this->fail('Il profilo ICA non verificato doveva essere rifiutato.');
        } catch (InvalidArgumentException) {
            // The failed batch is asserted below.
        }

        $batch = ImportBatch::query()->firstOrFail();

        $this->assertSame(ImportBatch::STATUS_FAILED, $batch->stato);
        $this->assertSame('ristorazione', $batch->profilo);
        $this->assertNotEmpty($batch->errori);
        $this->assertNotNull($batch->iniziato_il);
        $this->assertNotNull($batch->completato_il);
        $this->assertDatabaseCount('Listini', 0);
        $this->assertDatabaseCount('referenze_fornitore', 0);
        $this->assertDatabaseCount('listino_referenze', 0);
    }

    public function test_cross_supplier_list_reference_is_rejected_by_application_validation(): void
    {
        $listino = Listino::query()->create([
            'fornitore_id' => $this->supplier('ICA')->id,
            'nome_listino' => 'Scuole',
            'tipo' => 'acquisto',
            'attivo' => true,
        ]);
        $reference = ReferenzaFornitore::query()->create([
            'fornitore_id' => $this->supplier('IGROUP')->id,
            'supplier_code' => 'IG-1',
            'descrizione' => 'Prodotto',
            'source_hash' => str_repeat('a', 64),
        ]);

        $this->expectException(DomainException::class);

        ListinoReferenza::query()->create([
            'listino_id' => $listino->id,
            'referenza_fornitore_id' => $reference->id,
            'prezzo' => 1,
        ]);
    }

    public function test_catalog_upload_page_is_available_only_to_admin_users(): void
    {
        $adminRole = Role::query()->create(['name' => 'admin']);
        $clientRole = Role::query()->create(['name' => 'cliente']);
        $admin = User::factory()->create();
        $admin->assignRole($adminRole);
        $client = User::factory()->create();
        $client->assignRole($clientRole);

        $this->actingAs($admin);
        $this->assertTrue(ImportaCatalogo::canAccess());
        $this->get(ImportaCatalogo::getUrl())->assertOk();

        $this->actingAs($client);
        $this->assertFalse(ImportaCatalogo::canAccess());
        $this->get(ImportaCatalogo::getUrl())->assertForbidden();

        auth()->logout();
        $this->assertFalse(ImportaCatalogo::canAccess());
    }

    public function test_catalog_upload_accepts_files_up_to_ten_megabytes(): void
    {
        $page = app(ImportaCatalogo::class);
        $method = (new ReflectionClass($page))->getMethod('getFormSchema');
        $method->setAccessible(true);
        $fileUpload = collect($method->invoke($page))
            ->first(static fn (mixed $component): bool => $component instanceof FileUpload);

        $this->assertInstanceOf(FileUpload::class, $fileUpload);
        $this->assertSame(10 * 1024, $fileUpload->getMaxSize());
    }

    private function service(): CatalogPersistentImportService
    {
        return app(CatalogPersistentImportService::class);
    }

    private function supplier(string $code): Fornitore
    {
        return Fornitore::query()->where('code', $code)->firstOrFail();
    }

    /**
     * @param  array<int, array<int, mixed>>|null  $rows
     */
    private function icaFile(?array $rows = null, bool $withImages = true): string
    {
        $rows ??= [
            ['Detergenti', 'ICA-1', 'Prodotto ICA uno', 4.5, 'NR', '6 pezzi per cartone'],
            ['Detergenti', 'ICA-2', 'Prodotto ICA due', 6.2, 'NR', null],
        ];
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray(
            [
                ['CAT', 'Codice', 'Descrizione', 'Prezzo Netto', 'UM', 'Confezionamento'],
                ...$rows,
            ],
            null,
            'A1'
        );

        if ($withImages) {
            $imageFile = $this->tempPng();
            $this->addDrawing($sheet, $imageFile, 'A2', 'exact-image');
            $this->addDrawing($sheet, $imageFile, 'A20', 'uncertain-image');
        }

        return $this->writeSpreadsheet($spreadsheet, 'ica_catalog_');
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     */
    private function igroupFile(array $rows): string
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray(
            [
                [
                    'id',
                    'Inizio Validita',
                    'Fine Validita',
                    'Cod. Articolo',
                    'Articolo',
                    'UMprezzo',
                    'Prezzo',
                    'Prezzo Netto',
                    'Cod. Art. Cliente',
                    'UM',
                    'Imballo',
                ],
                ...$rows,
            ],
            null,
            'A1'
        );

        return $this->writeSpreadsheet($spreadsheet, 'igroup_catalog_');
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     */
    private function igroupCategoryFile(array $rows): string
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([
            [
                'id',
                'Cod. Articolo',
                'Articolo',
                'Prezzo Netto',
                'Cod. Categoria',
                'Categoria',
                'Cod. Macro Categoria',
                'Macro Categoria',
            ],
            ...$rows,
        ], null, 'A1');

        return $this->writeSpreadsheet($spreadsheet, 'igroup_categories_');
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function igroupRows(): array
    {
        return [
            [1, '2026-01-01', '2026-12-31', 'NEW', 'Articolo nuovo', 'NR', 10, 9, 'OLD', 'NR', 6],
            [2, '2026-01-01', '2026-12-31', 'OLD', 'Articolo precedente', 'NR', 8, 7, 'OLD', 'NR', 1],
            [3, '2026-01-01', '2026-12-31', 'DUP', 'Articolo duplicato', 'NR', 5, 4, 'DUP', 'NR', 1],
            [4, '2026-01-01', '2026-12-31', 'DUP', 'Articolo duplicato', 'NR', 5, 4, 'DUP', 'NR', 1],
            [5, '2026-01-01', '2026-12-31', 'A', 'Articolo A', 'NR', 3, 3, 'SHARED', 'NR', 1],
            [6, '2026-01-01', '2026-12-31', 'B', 'Articolo B', 'NR', 4, 4, 'SHARED', 'NR', 1],
        ];
    }

    private function addDrawing(
        \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet,
        string $path,
        string $coordinates,
        string $name,
    ): void {
        $drawing = new Drawing;
        $drawing->setName($name);
        $drawing->setPath($path);
        $drawing->setCoordinates($coordinates);
        $drawing->setWorksheet($sheet);
    }

    private function writeSpreadsheet(Spreadsheet $spreadsheet, string $prefix): string
    {
        $base = tempnam(sys_get_temp_dir(), $prefix);
        unlink($base);
        $path = $base.'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
        $this->tempFiles[] = $path;

        return $path;
    }

    private function tempPng(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'catalog_image_').'.png';
        file_put_contents(
            $path,
            base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
                true
            )
        );
        $this->tempFiles[] = $path;

        return $path;
    }
}
