<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Auth;

class EnsureClienteRole
{
    public function handle(Request $request, Closure $next): Response
    {
        // ✅ Evita di bloccare le rotte di login/logout/password reset del pannello clienti
        if ($request->is('clienti/login*') || $request->is('clienti/logout*') || $request->is('clienti/password-reset*')) {
            return $next($request);
        }

        $user = Auth::user();

        // 🔒 Non autenticato → redirect al login clienti
        if (!$user) {
            return redirect()->route('filament.clienti.auth.login');
        }

        // ⚠️ Autenticato ma senza ruolo "cliente"
        if (!$user->hasRole('cliente')) {
            // Logout di sicurezza per evitare sessioni miste
            Auth::logout();

            // Evita loop: reindirizza al login clienti
            return redirect()
                ->route('filament.clienti.auth.login')
                ->withErrors(['email' => 'Accesso riservato ai clienti.']);
        }

        // ✅ Tutto ok → prosegui
        return $next($request);
    }
}
