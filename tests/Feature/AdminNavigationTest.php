<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\ImportaCatalogo;
use App\Filament\Pages\ImportaListinoEnjoy;
use App\Filament\Resources\Categorias\CategoriaResource;
use App\Filament\Resources\CentroCostos\CentroCostoResource;
use App\Filament\Resources\Clientes\ClienteResource;
use App\Filament\Resources\Fornitori\FornitoreResource;
use App\Filament\Resources\ImportBatches\ImportBatchResource;
use App\Filament\Resources\Listinos\ListinoResource;
use App\Filament\Resources\OrdineResource;
use App\Filament\Resources\Products\ProductResource;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class AdminNavigationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $this->admin->assignRole(Role::query()->create(['name' => 'admin']));

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->admin);
    }

    public function test_menu_amministrativo_contiene_solo_le_voci_richieste_nell_ordine_corretto(): void
    {
        $labels = collect(Filament::getNavigation())
            ->flatMap(static fn (NavigationGroup $group) => $group->getItems())
            ->map(static fn ($item): string => $item->getLabel())
            ->values()
            ->all();

        $this->assertSame([
            'Import Cataloghi',
            'Fornitori',
            'Clienti',
            'Centri di Costo',
            'Listini',
            'Ordini',
        ], $labels);
        $this->assertSame($labels, array_values(array_unique($labels)));
    }

    public function test_destinazioni_visibili_sono_raggiungibili_dall_amministratore(): void
    {
        foreach ([
            ImportBatchResource::getUrl(),
            FornitoreResource::getUrl(),
            ClienteResource::getUrl(),
            CentroCostoResource::getUrl(),
            ListinoResource::getUrl(),
            OrdineResource::getUrl(),
        ] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_pagine_nascoste_restano_registrate_e_raggiungibili_direttamente(): void
    {
        foreach ([
            Dashboard::getUrl(),
            ImportaCatalogo::getUrl(),
            ImportaListinoEnjoy::getUrl(),
            CategoriaResource::getUrl(),
            ProductResource::getUrl(),
        ] as $url) {
            $this->get($url)->assertOk();
        }
    }
}
