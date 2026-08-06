<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\OrdineResource\Pages\ListOrdini;
use App\Models\Ordine;
use App\Models\User;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class AdminOrderExportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-06 15:30:00');
        Storage::fake('local');
        Storage::fake('public');
        $this->admin = User::factory()->create();
        $this->admin->assignRole(Role::query()->create(['name' => 'admin']));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_filtro_data_inclusivo_ed_export_usano_solo_gli_ordini_visibili(): void
    {
        $before = $this->order('PRIMA', '2026-07-31 23:59:59');
        $from = $this->order('DAL', '2026-08-01 00:00:00');
        $to = $this->order('AL', '2026-08-03 23:59:59', Ordine::PRIORITY_URGENT);
        $after = $this->order('DOPO', '2026-08-04 00:00:00');

        $component = Livewire::actingAs($this->admin)
            ->test(ListOrdini::class)
            ->filterTable('data_ordine', [
                'da' => '2026-08-01',
                'a' => '2026-08-03',
            ])
            ->assertCanSeeTableRecords([$from, $to])
            ->assertCanNotSeeTableRecords([$before, $after])
            ->assertActionExists('exportPdf')
            ->assertActionExists('exportExcel');

        $component
            ->callAction('exportExcel')
            ->assertFileDownloaded(
                'ordini-20260806-153000.xlsx',
                contentType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            );

        $xlsxContent = base64_decode((string) data_get($component->effects, 'download.content'), true);
        $this->assertIsString($xlsxContent);
        $temporaryPath = tempnam(sys_get_temp_dir(), 'orders_export_test_');
        $this->assertNotFalse($temporaryPath);
        file_put_contents($temporaryPath, $xlsxContent);

        try {
            $spreadsheet = IOFactory::load($temporaryPath);
            $sheet = $spreadsheet->getActiveSheet();
            $references = [
                $sheet->getCell('B5')->getValue(),
                $sheet->getCell('B6')->getValue(),
            ];
            $this->assertEqualsCanonicalizing(['DAL', 'AL'], $references);
            $this->assertNotContains('PRIMA', $references);
            $this->assertNotContains('DOPO', $references);
            $this->assertSame('Dal 2026-08-01 al 2026-08-03', $sheet->getCell('B2')->getValue());
            $spreadsheet->disconnectWorksheets();
        } finally {
            @unlink($temporaryPath);
        }

        $component
            ->callAction('exportPdf')
            ->assertFileDownloaded('ordini-20260806-153000.pdf', contentType: 'application/pdf');
        $pdfContent = base64_decode((string) data_get($component->effects, 'download.content'), true);
        $this->assertIsString($pdfContent);
        $this->assertStringStartsWith('%PDF-', $pdfContent);
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    private function order(string $reference, string $date, string $priority = Ordine::PRIORITY_STANDARD): Ordine
    {
        return Ordine::query()->create([
            'user_id' => $this->admin->getKey(),
            'stato' => Ordine::STATUS_NEW,
            'data_ordine' => $date,
            'inviato_da_nome' => 'Utente cliente',
            'riferimento_cliente' => $reference,
            'priorita' => $priority,
            'cliente_nome' => 'Cliente '.$reference,
            'centro_costo_nome' => 'Centro '.$reference,
            'fornitore_code' => 'ICA',
            'indirizzo_destinazione' => 'Via Test 1',
            'totale_lordo' => 10,
        ]);
    }
}
