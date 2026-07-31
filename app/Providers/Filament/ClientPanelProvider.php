<?php

namespace App\Providers\Filament;

use App\Filament\Client\Pages\Carrello;
use App\Filament\Client\Pages\Ordini;
use App\Filament\Client\Resources\Prodotti\ProdottoResource;
use App\Http\Middleware\EnsureClienteRole;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationBuilder;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
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

class ClientPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('clienti')
            ->path('clienti')
            ->login() // genera /clienti/login /clienti/logout ecc.
            ->brandName('Portale Clienti Enjoy')
            ->viteTheme('resources/css/filament/clienti/theme.css')
            ->colors([
                'primary' => Color::Blue,
            ])
            ->navigation(function (NavigationBuilder $builder): NavigationBuilder {
                return $builder->groups([
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
                            NavigationItem::make()
                                ->label('I miei ordini')
                                ->icon('heroicon-o-clipboard-document-list')
                                ->url(Ordini::getUrl()),
                        ]),
                    NavigationGroup::make()
                        ->label('Catalogo')
                        ->items([
                            NavigationItem::make()
                                ->label('Catalogo')
                                ->icon('heroicon-o-rectangle-stack')
                                ->url(ProdottoResource::getUrl('index')),
                        ]),
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

                EnsureClienteRole::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
