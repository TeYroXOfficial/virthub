<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureIsAdmin
{
    /**
     * @param  string  $level  'admin' (domyślnie) albo 'staff' — endpointy
     *                         wsparcia dopuszczają rolę support, reszta nie.
     */
    public function handle(Request $request, Closure $next, string $level = 'admin'): Response
    {
        $user = $request->user();

        $allowed = match ($level) {
            'staff' => $user?->isStaff(),
            default => $user?->isAdmin(),
        };

        if (! $allowed || $user->isSuspended()) {
            abort(403, 'Ta operacja wymaga uprawnień administratora.');
        }

        return $next($request);
    }
}
