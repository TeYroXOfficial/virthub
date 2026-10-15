<?php

namespace App\Http\Middleware;

use App\Domain\Security\StepUp;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Operacje wrażliwe wymagają świeżego potwierdzenia tożsamości. Tokeny API
 * (integracje) nie mają sesji — dla nich obowiązują zakresy tokenu.
 */
class RequireStepUp
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession() || $request->user() === null || ! $request->user('web') || StepUp::fresh($request)) {
            return $next($request);
        }
        $message = __('Potwierdź swoją tożsamość, aby wykonać tę operację.');
        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'confirm_url' => route('confirm')], 423);
        }
        // Wracamy na stronę, z której wysłano formularz — operację trzeba powtórzyć.
        $request->session()->put('confirm.return', $request->isMethod('GET') ? $request->fullUrl() : url()->previous());

        return redirect()->route('confirm')->with('status', $message);
    }
}
