<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureClienteRole
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // ✅ Se siamo sulla pagina di login o password reset, salta il controllo
        if ($request->is('clienti/login') || $request->is('clienti/logout') || $request->is('clienti/forgot-password')) {
            return $next($request);
        }

        // Non autenticato → vai al login
        if (!$user) {
            return redirect('/clienti/login');
        }

        // Autenticato ma senza ruolo "cliente" → 403
        if (!$user->hasRole('cliente')) {
            abort(403, 'Accesso non autorizzato (ruolo cliente richiesto).');
        }

        return $next($request);
    }
}
