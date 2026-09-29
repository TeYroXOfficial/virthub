<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\AppServer;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Logowanie do SFTP aplikacji: agent pyta, czy użytkownik panelu z tym
 * hasłem może zarządzać aplikacją. Agent nie zna haseł — dostaje tylko
 * „tak” albo „nie”.
 *
 * Żądanie podpisuje sekret węzła, na którym leży aplikacja (ten sam co przy
 * wynikach zadań) — inny węzeł nie zapyta o cudzą aplikację, a nikt spoza
 * węzłów nie użyje tego endpointu do zgadywania haseł. Nieudane próby są
 * dodatkowo limitowane na użytkownika.
 */
class SftpAuthController extends Controller
{
    use VerifiesNodeSignature;

    private const MAX_FAILURES = 10;

    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->json()->all();
        $uuid = $payload['uuid'] ?? null;
        $userId = $payload['user_id'] ?? null;
        $password = $payload['password'] ?? null;

        if (! is_string($uuid) || ! is_int($userId) || ! is_string($password) || $password === '') {
            return $this->deny(422);
        }

        $app = AppServer::with('hypervisor')->where('uuid', $uuid)->first();

        if ($app === null || $app->hypervisor === null) {
            return $this->deny(404);
        }

        if (! $this->hasValidNodeSignature($request,(string) $app->hypervisor->callback_secret)) {
            Log::warning(__('Odrzucono logowanie SFTP z nieprawidłowym podpisem węzła'), ['app' => $app->uuid, 'ip' => $request->ip()]);

            return $this->deny(401);
        }

        $key = "sftp-auth:{$userId}";
        if (RateLimiter::tooManyAttempts($key, self::MAX_FAILURES)) {
            return $this->deny(429);
        }

        $user = User::find($userId);
        // Aplikacja z osobnym hasłem SFTP przyjmuje tylko je; bez niego — hasło do panelu.
        $secret = $app->sftp_password ?: $user?->password;
        $allowed = $user !== null
            && is_string($secret) && Hash::check($password, $secret)
            && $user->can('operate', $app)
            && ! $app->isSuspended()
            && $app->status !== AppServer::STATUS_DELETING;

        if (! $allowed) {
            RateLimiter::hit($key, 300);

            return $this->deny(403);
        }

        RateLimiter::clear($key);

        return response()->json(['allowed' => true]);
    }

    private function deny(int $status): JsonResponse
    {
        return response()->json(['allowed' => false], $status);
    }
}
