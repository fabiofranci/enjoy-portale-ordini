<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Filament\Facades\Filament;

class EnsureClienteRole
{
    public function handle(Request $request, Closure $next): Response
    {
        // 👇 Recupera l'utente autenticato nel panel corrente
        $user = Filament::auth()->user();

        // Se siamo nelle rotte di login/logout, lasciamo passare
        if ($request->is('clienti/login') || $request->is('clienti/logout') || $request->is('clienti/forgot-password')) {
            return $next($request);
        }

        // Non autenticato → redirect al login clienti
        if (!$user) {
            return redirect()
                ->route('filament.clienti.auth.login');
        }

        // Autenticato ma non ha ruolo "cliente"
        if (!$user->hasRole('cliente')) {
            return redirect()
                ->route('filament.clienti.auth.login')
                ->withErrors(['email' => 'Accesso riservato ai clienti.']);
        }

        // ✅ Tutto ok → prosegui
        return $next($request);
    }
}
