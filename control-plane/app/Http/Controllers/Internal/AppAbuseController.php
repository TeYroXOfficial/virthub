<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\AppServer;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Zgłoszenie nadużycia z węzła: ochrona aplikacji w agencie wykryła PteroVM,
 * proot/QEMU, koparkę albo zdalną powłokę (proces albo pliki).
 *
 * Kontener jest już zabity albo start zablokowany — panel zapisuje
 * znaleziska, a przy znaleziskach blokujących zawiesza aplikację, żeby
 * klient nie uruchamiał jej w kółko. Personel może zwolnić aplikację
 * z ochrony (fałszywy alarm) i ją odwiesić.
 */
class AppAbuseController extends Controller
{
    use VerifiesNodeSignature;

    private const CATEGORIES = ['vm', 'miner', 'remote-shell', 'tunnel'];

    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->json()->all();
        $uuid = $payload['uuid'] ?? null;
        $action = $payload['action'] ?? null;
        $findings = $payload['findings'] ?? null;

        if (! is_string($uuid) || ! in_array($action, ['killed', 'blocked', 'reported'], true) || ! is_array($findings) || $findings === []) {
            return response()->json(['message' => 'Nieprawidłowe zgłoszenie.'], 422);
        }

        $app = AppServer::with('hypervisor')->where('uuid', $uuid)->first();

        if ($app === null || $app->hypervisor === null) {
            return response()->json(['message' => 'Nieznana aplikacja.'], 404);
        }

        if (! $this->hasValidNodeSignature($request, (string) $app->hypervisor->callback_secret)) {
            Log::warning(__('Odrzucono zgłoszenie nadużycia z nieprawidłowym podpisem węzła'), ['app' => $app->uuid, 'ip' => $request->ip()]);

            return response()->json(['message' => 'Nieprawidłowy podpis.'], 401);
        }

        $clean = collect($findings)
            ->filter(fn ($f) => is_array($f) && in_array($f['category'] ?? null, self::CATEGORIES, true))
            ->take(20)
            ->map(fn (array $f) => [
                'category' => $f['category'],
                'level' => ($f['level'] ?? '') === 'block' ? 'block' : 'warn',
                'source' => ($f['source'] ?? '') === 'process' ? 'process' : 'file',
                'detail' => mb_substr((string) ($f['detail'] ?? ''), 0, 200),
            ])
            ->values();

        if ($clean->isEmpty()) {
            return response()->json(['message' => 'Nieprawidłowe zgłoszenie.'], 422);
        }

        if ($app->abuse_exempt) {
            return response()->json(['ignored' => true]);
        }

        $app->forceFill([
            'abuse_detected_at' => now(),
            'abuse_findings' => ['action' => $action, 'findings' => $clean->all()],
        ])->save();
        AuditLog::record('app.abuse', $app, ['action' => $action, 'findings' => $clean->all()]);

        $blocking = $clean->where('level', 'block');
        $suspended = false;

        if ($blocking->isNotEmpty() && config('virthub.apps_abuse_suspend') && ! $app->isSuspended()) {
            // Kontener już nie działa (zabity albo nie wystartował) — bez wołania węzła.
            // Żądanie przychodzi z węzła, bez języka użytkownika — powód zapisujemy
            // w domyślnym języku panelu, jak inne powody zawieszenia.
            $locale = config('virthub.default_locale');
            $what = $blocking->map(fn ($f) => self::label($f['category'], $locale).' ('.$f['detail'].')')->unique()->take(3)->implode('; ');
            $app->forceFill([
                'suspended_at' => now(),
                'suspension_reason' => mb_substr(__('Automatyczna blokada — wykryto: :what', ['what' => $what], $locale), 0, 250),
            ])->save();
            AuditLog::record('app.suspend', $app, ['reason' => $app->suspension_reason, 'automatic' => true]);
            $suspended = true;
        }

        Log::warning(__('Wykryto nadużycie w aplikacji'), ['app' => $app->uuid, 'action' => $action, 'suspended' => $suspended, 'findings' => $clean->all()]);

        return response()->json(['suspended' => $suspended]);
    }

    public static function label(string $category, ?string $locale = null): string
    {
        return match ($category) {
            'vm' => __('system w kontenerze (PteroVM/proot/QEMU)', [], $locale),
            'miner' => __('koparka kryptowalut', [], $locale),
            'remote-shell' => __('zdalna powłoka / serwer SSH', [], $locale),
            'tunnel' => __('tunel sieciowy', [], $locale),
            default => $category,
        };
    }
}
