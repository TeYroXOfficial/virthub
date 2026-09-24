<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureIsAdmin
{
    /**
     * @param  string  $level  'admin' (domyślnie) — pełny administrator;
     *                         'staff' — personel (support lub admin);
     *                         'panel' — personel z dostępem do choć jednego działu;
     *                         'admin.xxx' — personel z danym uprawnieniem działu.
     */
    public function handle(Request $request, Closure $next, string $level = 'admin'): Response
    {
        $user = $request->user();

        $allowed = match (true) {
            $level === 'staff' => $user?->isStaff(),
            $level === 'panel' => $user?->hasAnyAdminPermission(),
            str_starts_with($level, 'admin.') => $user?->isStaff() && $user->hasPermission($level),
            default => $user?->isAdmin(),
        };

        if (! $allowed || $user->isSuspended()) {
            abort(403, 'Nie masz uprawnień do tej części panelu.');
        }

        return $next($request);
    }
}
