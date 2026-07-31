<?php

declare(strict_types=1);

namespace App\Filament\Client\Pages;

use App\Models\Ordine;
use App\Models\User;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;

class Ordini extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $navigationLabel = 'I miei ordini';

    protected static ?string $title = 'I miei ordini';

    protected static ?string $slug = 'ordini';

    protected static ?int $navigationSort = 30;

    protected string $view = 'filament.client.pages.ordini';

    /** @return Collection<int, Ordine> */
    public function orders(): Collection
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            abort(403);
        }

        return Ordine::query()
            ->where('user_id', $user->getKey())
            ->with(['centroCosto', 'fornitore', 'items'])
            ->latest()
            ->get();
    }
}
