<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Zawieszenie konta działa od razu, nie dopiero przy następnym logowaniu:
 * otwarta sesja zawieszonego użytkownika jest kończona przy pierwszym żądaniu.
 */
class EnsureNotSuspended
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->isSuspended()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'email' => 'To konto zostało zablokowane. Skontaktuj się z obsługą.',
            ]);
        }

        return $next($request);
    }
}
