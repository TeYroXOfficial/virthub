<?php

namespace App\Domain\Agent;

use App\Models\Hypervisor;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Klient API agenta hypervisora.
 *
 * Control plane nigdy nie loguje się na hypervisor po SSH — cała komunikacja
 * idzie tędy. Każde żądanie jest podpisane HMAC-SHA256 na kanonicznej postaci:
 *
 *     {timestamp}\n{METODA}\n{ścieżka}\n{sha256(body)}
 *
 * Podpis obejmuje ścieżkę i treść, więc przechwycone żądanie „zatrzymaj VPS 5"
 * nie da się przerobić na „usuń VPS 9".
 */
class AgentClient
{
    private const TIMEOUT = 20;
    private const CONNECT_TIMEOUT = 5;

    public function __construct(private readonly Hypervisor $hypervisor) {}

    // --- odczyty ------------------------------------------------------------

    public function health(): array
    {
        return $this->request('GET', '/health');
    }

    public function stats(string $uuid): array
    {
        return $this->request('GET', "/vm/{$uuid}/stats");
    }

    public function job(string $jobId): array
    {
        return $this->request('GET', "/jobs/{$jobId}");
    }

    // --- operacje (zwracają identyfikator zadania po stronie agenta) --------

    public function createVm(array $payload): string
    {
        return $this->jobId($this->request('POST', '/vm', $payload));
    }

    public function power(string $uuid, string $action): string
    {
        return $this->jobId($this->request('POST', "/vm/{$uuid}/power", ['action' => $action]));
    }

    public function rebuild(string $uuid, array $payload): string
    {
        return $this->jobId($this->request('POST', "/vm/{$uuid}/rebuild", $payload));
    }

    public function resize(string $uuid, array $payload): string
    {
        return $this->jobId($this->request('POST', "/vm/{$uuid}/resize", $payload));
    }

    public function delete(string $uuid): string
    {
        return $this->jobId($this->request('DELETE', "/vm/{$uuid}"));
    }

    public function snapshot(string $uuid, string $name): string
    {
        return $this->jobId($this->request('POST', "/vm/{$uuid}/snapshot", ['name' => $name]));
    }

    public function restore(string $uuid, string $name): string
    {
        return $this->jobId($this->request('POST', "/vm/{$uuid}/snapshot/restore", ['name' => $name]));
    }

    public function configureNetwork(string $uuid, array $payload): string
    {
        return $this->jobId($this->request('PUT', "/vm/{$uuid}/network", $payload));
    }

    // --- transport ----------------------------------------------------------

    private function jobId(array $response): string
    {
        if (! isset($response['job_id'])) {
            throw new AgentException(
                "Agent {$this->hypervisor->name} nie zwrócił identyfikatora zadania."
            );
        }

        return $response['job_id'];
    }

    private function request(string $method, string $path, ?array $body = null): array
    {
        $payload = $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        $timestamp = (string) time();

        $request = Http::timeout(self::TIMEOUT)
            ->connectTimeout(self::CONNECT_TIMEOUT)
            ->withOptions(['verify' => $this->tlsVerification()])
            ->withHeaders([
                'X-VH-Timestamp' => $timestamp,
                'X-VH-Signature' => $this->sign($timestamp, $method, $path, $payload),
                'Accept' => 'application/json',
            ]);

        if ($body !== null) {
            $request = $request->withBody($payload, 'application/json');
        }

        try {
            $response = $request->send($method, $this->hypervisor->agent_url.$path);
        } catch (ConnectionException $e) {
            throw new AgentException(
                "Hypervisor {$this->hypervisor->name} jest nieosiągalny: {$e->getMessage()}",
                previous: $e,
            );
        }

        return $this->handle($response, $method, $path);
    }

    private function handle(Response $response, string $method, string $path): array
    {
        if ($response->successful()) {
            return $response->json() ?? [];
        }

        // Agent zwraca powód w polu `detail` — przekazujemy go dalej zamiast
        // generycznego „błąd serwera", bo to on wie, co konkretnie zawiodło.
        $detail = $response->json('detail') ?? $response->body();

        Log::warning('Agent odrzucił żądanie', [
            'hypervisor' => $this->hypervisor->id,
            'request' => "{$method} {$path}",
            'status' => $response->status(),
            'detail' => $detail,
        ]);

        throw new AgentException(
            "Hypervisor {$this->hypervisor->name} odrzucił operację: {$detail}",
            status: $response->status(),
        );
    }

    /**
     * Węzeł zarejestrowany automatycznie przedstawia się certyfikatem
     * wygenerowanym u siebie — nie ma własnej domeny, więc nie ma jak wystawić
     * mu certyfikatu od publicznego urzędu. Zamiast wyłączać weryfikację
     * (`verify => false`, co otwiera drogę na atak pośrednika), przypinamy ten
     * konkretny certyfikat: połączenie przejdzie wyłącznie z tym węzłem.
     *
     * Węzeł z własną domeną i certyfikatem Let's Encrypt nie ma zapisanego
     * certyfikatu i weryfikuje się normalnie, wobec systemowych urzędów.
     *
     * @return string|bool ścieżka do przypiętego certyfikatu albo `true`
     */
    private function tlsVerification(): string|bool
    {
        return app(\App\Domain\Provisioning\HypervisorEnrollment::class)
            ->certificatePath($this->hypervisor) ?? true;
    }

    private function sign(string $timestamp, string $method, string $path, string $body): string
    {
        $canonical = implode("\n", [
            $timestamp,
            strtoupper($method),
            $path,
            hash('sha256', $body),
        ]);

        return hash_hmac('sha256', $canonical, $this->hypervisor->agent_token);
    }
}
