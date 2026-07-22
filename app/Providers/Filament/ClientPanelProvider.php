<?php

namespace App\Providers\Filament;

use Illuminate\Support\Facades\Route;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use App\Http\Middleware\EnsureClienteRole; // ✅
use App\Http\Controllers\Client\CartController;
use Filament\Navigation\NavigationBuilder;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use App\Filament\Client\Resources\Prodotti\ProdottoResource;
use App\Models\Categoria;
use App\Filament\Client\Pages\Carrello;

class ClientPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('clienti')
            ->path('clienti')
            ->login() // genera /clienti/login /clienti/logout ecc.
            ->brandName('Portale Clienti Enjoy')
            ->colors([
                'primary' => Color::Blue,
            ])
->navigation(function (NavigationBuilder $builder): NavigationBuilder {
    return $builder->groups([
        // 🏠 Dashboard + Carrello
        NavigationGroup::make()
            ->label('Navigazione')
            ->items([
                NavigationItem::make()
                    ->label('Dashboard')
                    ->icon('heroicon-o-home')
                    ->url(Dashboard::getUrl()),

                NavigationItem::make()
                    ->label('Carrello')
                    ->icon('heroicon-o-shopping-cart')
                    ->url(Carrello::getUrl()),
            ]),

        // 📦 Catalogo dinamico
        NavigationGroup::make()
            ->label('Catalogo')
            ->items(array_merge(
                [
                    NavigationItem::make()
                        ->label('Tutti i prodotti')
                        ->icon('heroicon-o-rectangle-stack')
                        ->url(ProdottoResource::getUrl('index')),
                ],
                Categoria::whereNull('categoria_padre_id')
                    ->orderBy('nome')
                    ->get()
                    ->map(function ($categoria) {
                        return NavigationItem::make()
                            ->label($categoria->nome)
                            ->icon('heroicon-o-tag')
                            ->url(ProdottoResource::getUrl('index', [
                                'categoria' => $categoria->id,
                            ]));
                    })
                    ->toArray()
            )),
    ]);
})
            ->discoverResources(in: app_path('Filament/Client/Resources'), for: 'App\\Filament\\Client\\Resources')
            ->discoverPages(in: app_path('Filament/Client/Pages'), for: 'App\\Filament\\Client\\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Client/Widgets'), for: 'App\\Filament\\Client\\Widgets')
            ->widgets([])
            // ordine middleware consigliato per Filament v4
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,

                EnsureClienteRole::class, // ✅ filtro ruolo CLIENTE per tutto il panel
            ])
            ->authMiddleware([
                Authenticate::class, // ✅ forza autenticazione
            ])
            ->routes(function () {
                Route::post('/carrello/update', [\App\Http\Controllers\Client\CartController::class, 'update'])
                    ->name('pages.carrello.update');

                Route::get('/carrello/add/{prodotto}', [CartController::class, 'add'])
                    ->name('pages.carrello.add');

                Route::get('/carrello/remove/{id}', [CartController::class, 'remove'])
                    ->name('pages.carrello.remove');

                Route::post('/carrello/checkout', [CartController::class, 'checkout'])
                    ->name('pages.carrello.checkout');
            });


    }
}
