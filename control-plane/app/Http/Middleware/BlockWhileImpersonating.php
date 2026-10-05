<?php

namespace App\Http\Middleware;

use App\Domain\Access\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Hasło, e-mail i sesje klienta zmienia tylko on sam — nie administrator zalogowany jako klient. */
class BlockWhileImpersonating
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Impersonation::active()) {
            return back()->withErrors(['impersonate' => __('Podczas logowania jako klient nie można zmieniać zabezpieczeń jego konta.')]);
        }

        return $next($request);
    }
}
