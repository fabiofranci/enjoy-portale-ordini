<?php

namespace App\Filament\ClientPanel\Pages;

use Filament\Pages\Page;

class Dashboard extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';
    protected static string $view = 'filament.client-panel.pages.dashboard';
    protected static ?string $title = 'Benvenuto nel Portale Clienti';

    public function mount(): void
    {
        // Qui puoi caricare i dati del cliente loggato, ordini, ecc.
    }
}
