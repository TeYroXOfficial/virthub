<?php

namespace App\Http\Controllers\Internal;

use App\Domain\Provisioning\AgentResultApplier;
use App\Http\Controllers\Controller;
use App\Models\ServerJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Odbiór wyników zadań raportowanych przez agentów.
 *
 * Endpoint jest publiczny w sensie routingu (agent nie loguje się do panelu),
 * więc cała ochrona opiera się na podpisie HMAC. Kolejność jest istotna:
 * najpierw znajdujemy zadanie po identyfikatorze z ciała, potem weryfikujemy
 * podpis sekretem hypervisora, do którego to zadanie należy — i dopiero wtedy
 * cokolwiek zmieniamy. Samo odnalezienie rekordu nic nie ujawnia, a bez
 * poprawnego podpisu żaden stan się nie zmienia.
 */
class AgentCallbackController extends Controller
{
    private const MAX_CLOCK_SKEW = 300;

    public function __invoke(Request $request, AgentResultApplier $applier): JsonResponse
    {
        $payload = $request->json()->all();
        $agentJobId = $payload['job_id'] ?? null;

        if (! is_string($agentJobId)) {
            return response()->json(['message' => 'Brak identyfikatora zadania.'], 422);
        }

        $job = ServerJob::with('server.hypervisor')
            ->where('agent_job_id', $agentJobId)
            ->first();

        if ($job === null || $job->server?->hypervisor === null) {
            // 404 mówi agentowi „przestań to wysyłać" — zadanie nie istnieje
            // po naszej stronie i ponawianie niczego nie zmieni.
            return response()->json(['message' => 'Nieznane zadanie.'], 404);
        }

        if (! $this->hasValidSignature($request, $job->server->hypervisor->callback_secret)) {
            Log::warning('Odrzucono callback agenta z nieprawidłowym podpisem', [
                'agent_job_id' => $agentJobId,
                'ip' => $request->ip(),
            ]);

            return response()->json(['message' => 'Nieprawidłowy podpis.'], 401);
        }

        $applier->apply($job, [
            'status' => $payload['status'] ?? 'failed',
            'result' => $payload['result'] ?? null,
            'error' => $payload['error'] ?? null,
        ]);

        return response()->json(['message' => 'Przyjęto.']);
    }

    private function hasValidSignature(Request $request, string $secret): bool
    {
        $signature = $request->header('X-VH-Signature', '');
        $timestamp = $request->header('X-VH-Timestamp', '');

        if ($signature === '' || ! ctype_digit($timestamp)) {
            return false;
        }

        if (abs(time() - (int) $timestamp) > self::MAX_CLOCK_SKEW) {
            return false;
        }

        $canonical = implode("\n", [
            $timestamp,
            'POST',
            $request->getPathInfo(),
            hash('sha256', $request->getContent()),
        ]);

        return hash_equals(hash_hmac('sha256', $canonical, $secret), $signature);
    }
}
