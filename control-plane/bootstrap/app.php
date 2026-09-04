<?php

use App\Domain\Agent\AgentException;
use App\Domain\Provisioning\NoAddressesException;
use App\Domain\Provisioning\NoCapacityException;
use App\Http\Middleware\EnsureIsAdmin;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin' => EnsureIsAdmin::class,
            // Zakresy tokenów Sanctum: token integracji rozliczeniowej ma
            // dostęp wyłącznie do endpointów billingowych.
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
        ]);

        // Panel korzysta z tych samych endpointów API co integracje zewnętrzne,
        // tyle że uwierzytelnia się ciasteczkiem sesji. Bez tego przycisk
        // „Uruchom" w przeglądarce wymagałby osobnego tokenu.
        $middleware->statefulApi();

        // Callback agenta jest podpisany HMAC i przychodzi spoza przeglądarki —
        // token CSRF nie ma tam zastosowania ani sensu.
        $middleware->validateCsrfTokens(except: [
            'api/internal/agent/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Wyjątki domenowe to komunikaty dla użytkownika, nie awarie systemu.
        // Bez tego mapowania klient zamiast „brak wolnych adresów IP" widziałby
        // błąd 500, a my dostawalibyśmy zgłoszenie o awarii panelu.

        $exceptions->render(function (NoCapacityException $e, Request $request) {
            return $request->expectsJson()
                ? response()->json(['message' => $e->getMessage()], 503)
                : back()->withErrors(['capacity' => $e->getMessage()]);
        });

        $exceptions->render(function (NoAddressesException $e, Request $request) {
            return $request->expectsJson()
                ? response()->json(['message' => $e->getMessage()], 503)
                : back()->withErrors(['ip' => $e->getMessage()]);
        });

        $exceptions->render(function (DomainException $e, Request $request) {
            return $request->expectsJson()
                ? response()->json(['message' => $e->getMessage()], 409)
                : back()->withErrors(['state' => $e->getMessage()]);
        });

        $exceptions->render(function (AgentException $e, Request $request) {
            return $request->expectsJson()
                ? response()->json(['message' => $e->getMessage()], 502)
                : back()->withErrors(['agent' => $e->getMessage()]);
        });
    })->create();
