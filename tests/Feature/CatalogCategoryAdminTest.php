<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\Listinos\Pages\ViewListino;
use App\Filament\Resources\Listinos\RelationManagers\ReferenzeRelationManager;
use App\Models\CategoriaCatalogo;
use App\Models\Fornitore;
use App\Models\Listino;
use App\Models\ListinoReferenza;
use App\Models\ReferenzaFornitore;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class CatalogCategoryAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_catalog_shows_and_filters_supplier_categories_and_uncategorized_references(): void
    {
        $ica = $this->supplier('ICA');
        $igroup = $this->supplier('IGROUP');
        $listino = $this->listino($ica, 'ICA Scuole');
        $category = $this->category($ica, '10', 'Detergenti');
        $otherSupplierCategory = $this->category($igroup, '10', 'Detergenti');
        $categorizedReference = $this->reference($ica, 'ICA-CAT');
        $uncategorizedReference = $this->reference($ica, 'ICA-NO-CAT');
        $categorizedReference->categorie()->attach($category);
        $categorizedPrice = $this->price($listino, $categorizedReference, 1);
        $uncategorizedPrice = $this->price($listino, $uncategorizedReference, 2);
        $admin = User::factory()->create();
        $admin->assignRole(Role::query()->create(['name' => 'admin']));
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $component = Livewire::actingAs($admin)
            ->test(ReferenzeRelationManager::class, [
                'ownerRecord' => $listino,
                'pageClass' => ViewListino::class,
            ])
            ->assertTableColumnVisible('catalog_categories')
            ->assertTableColumnStateSet(
                'catalog_categories',
                ['ICA / Detergenti'],
                $categorizedPrice,
            )
            ->assertTableFilterExists(
                'categoria_catalogo',
                static fn (SelectFilter $filter): bool => $filter->getPlaceholder() === 'Tutte le categorie'
                    && $filter->getOptions() === [
                        CategoriaCatalogo::FILTER_WITHOUT_CATEGORY => 'Senza categoria',
                        $category->id => 'ICA / Detergenti (1)',
                    ],
            )
            ->assertCanSeeTableRecords([$categorizedPrice, $uncategorizedPrice]);

        $component
            ->filterTable('categoria_catalogo', $category->id)
            ->assertCanSeeTableRecords([$categorizedPrice])
            ->assertCanNotSeeTableRecords([$uncategorizedPrice])
            ->filterTable(
                'categoria_catalogo',
                CategoriaCatalogo::FILTER_WITHOUT_CATEGORY,
            )
            ->assertCanSeeTableRecords([$uncategorizedPrice])
            ->assertCanNotSeeTableRecords([$categorizedPrice]);

        $this->assertNotSame($category->id, $otherSupplierCategory->id);
        $this->assertSame('ICA', $category->fornitore->code);
        $this->assertSame('IGROUP', $otherSupplierCategory->fornitore->code);
    }

    private function supplier(string $code): Fornitore
    {
        return Fornitore::query()->create([
            'code' => $code,
            'nome' => $code,
            'attivo' => true,
        ]);
    }

    private function listino(Fornitore $supplier, string $name): Listino
    {
        return Listino::query()->create([
            'fornitore_id' => $supplier->getKey(),
            'nome_listino' => $name,
            'tipo' => 'acquisto',
            'attivo' => true,
        ]);
    }

    private function category(
        Fornitore $supplier,
        string $code,
        string $name,
    ): CategoriaCatalogo {
        return CategoriaCatalogo::query()->create([
            'fornitore_id' => $supplier->getKey(),
            'codice' => $code,
            'nome' => $name,
            'slug' => str($name)->slug()->toString(),
            'attiva' => true,
        ]);
    }

    private function reference(Fornitore $supplier, string $code): ReferenzaFornitore
    {
        return ReferenzaFornitore::query()->create([
            'fornitore_id' => $supplier->getKey(),
            'supplier_code' => $code,
            'descrizione' => "Referenza {$code}",
            'attivo' => true,
            'ordinabile' => true,
            'source_hash' => hash('sha256', $supplier->code.$code),
        ]);
    }

    private function price(
        Listino $listino,
        ReferenzaFornitore $reference,
        float $amount,
    ): ListinoReferenza {
        return ListinoReferenza::query()->create([
            'listino_id' => $listino->getKey(),
            'referenza_fornitore_id' => $reference->getKey(),
            'prezzo' => $amount,
            'attivo' => true,
            'ordinabile' => true,
        ]);
    }
}
