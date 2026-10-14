<?php

namespace App\Domain\Agent;

use App\Domain\Provisioning\HypervisorEnrollment;
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

    /** Świeży audyt ochrony hosta (znane ucieczki z maszyn, jądro, podatności CPU). */
    public function securityAudit(): array
    {
        return $this->request('GET', '/system/security');
    }

    /** Polityka zagnieżdżonej wirtualizacji: auto (tylko na jądrze z poprawkami) albo allow. */
    public function setNestedPolicy(string $policy): array
    {
        return $this->request('PUT', '/system/nested', ['policy' => $policy]);
    }

    /** Szczegółowe statystyki hosta i usług (strona Monitorowanie). */
    public function monitor(): array
    {
        return $this->request('GET', '/system/monitor');
    }

    public function stats(string $uuid): array
    {
        return $this->request('GET', "/vm/{$uuid}/stats");
    }

    /** System zainstalowany w maszynie (odczyt z jej wnętrza). */
    public function guestOs(string $uuid): array
    {
        return $this->request('GET', "/vm/{$uuid}/os");
    }

    public function job(string $jobId): array
    {
        return $this->request('GET', "/jobs/{$jobId}");
    }

    /** Pobranie obrazu ISO do biblioteki węzła (zadanie w tle). */
    /** @param  int|null  $customMaxGb  obraz klienta: agent odrzuca adresy prywatne i pilnuje limitu */
    public function downloadIso(string $filename, string $url, ?string $sha256, ?int $customMaxGb = null): string
    {
        return $this->jobId($this->request('POST', '/images/iso', array_filter([
            'name' => $filename,
            'url' => $url,
            'sha256' => $sha256,
            'untrusted' => $customMaxGb !== null ? true : null,
            'max_gb' => $customMaxGb,
        ], fn ($v) => $v !== null)));
    }

    public function deleteIso(string $filename): array
    {
        return $this->request('DELETE', '/images/iso/'.rawurlencode($filename));
    }

    /** Nowe hasło roota (albo Administratora w Windows) w działającym systemie. */
    public function resetPassword(string $uuid, string $password, string $username = 'root'): string
    {
        return $this->jobId($this->request('POST', "/vm/{$uuid}/password", array_filter([
            'password' => $password,
            'username' => $username === 'root' ? null : $username,
        ])));
    }

    /** @param  array<string, mixed>  $recipe */
    public function buildTemplate(array $recipe): string
    {
        return $this->jobId($this->request('POST', '/templates/build', $recipe));
    }

    /** Płyta w maszynie i kolejność rozruchu; restart = zastosuj od razu. */
    /** @param  array{type:string, config:array<string, mixed>}  $target */
    public function backup(string $uuid, string $key, array $target, bool $compress): string
    {
        return $this->jobId($this->request('POST', "/vm/{$uuid}/backups", ['key' => $key, 'target' => $target, 'compress' => $compress]));
    }

    /** @param  array{type:string, config:array<string, mixed>}  $target */
    public function restoreBackup(string $uuid, string $key, array $target, ?string $sha256): string
    {
        return $this->jobId($this->request('POST', "/vm/{$uuid}/backups/restore", array_filter(['key' => $key, 'target' => $target, 'sha256' => $sha256])));
    }

    /**
     * @param  list<string>  $keys
     * @param  array{type:string, config:array<string, mixed>}  $target
     */
    public function deleteBackups(array $keys, array $target): string
    {
        return $this->jobId($this->request('POST', '/backups/delete', ['keys' => $keys, 'target' => $target]));
    }

    /** @return array{iommu: bool, devices: list<array<string, mixed>>} */
    public function pciDevices(): array
    {
        return $this->request('GET', '/system/pci');
    }

    /**
     * Pojemność magazynów danych widziana z węzła.
     *
     * @param  list<array<string, mixed>>  $datastores
     * @return list<array{id:int, ok:bool, total_gb?:int, free_gb?:int, error?:string}>
     */
    public function datastoresStatus(array $datastores): array
    {
        return (array) ($this->request('POST', '/system/datastores', ['datastores' => $datastores], 30)['datastores'] ?? []);
    }

    /** @param  list<array{address:string, xml?:string}>  $devices */
    public function setHostdevs(string $uuid, array $devices, bool $restart): string
    {
        return $this->jobId($this->request('PUT', "/vm/{$uuid}/hostdevs", ['devices' => $devices, 'restart' => $restart]));
    }

    /** @param  'start'|'commit'|'discard'  $action */
    public function elevate(string $uuid, string $action): string
    {
        return $this->jobId($this->request('POST', "/vm/{$uuid}/elevate", ['action' => $action]));
    }

    public function mountIso(string $uuid, ?string $filename, bool $boot, bool $restart): string
    {
        return $this->jobId($this->request('POST', "/vm/{$uuid}/iso", [
            'iso' => $filename,
            'boot' => $boot,
            'restart' => $restart,
        ]));
    }

    /** Stan aktualizacji węzła i commit, z którego działa agent. */
    public function updateStatus(): array
    {
        return $this->request('GET', '/system/update');
    }

    /** Zleca aktualizację węzła — wykonuje ją usługa systemd na węźle. */
    public function requestUpdate(): array
    {
        return $this->request('POST', '/system/update');
    }

    /** Stan instalacji MariaDB na węźle; po zakończeniu — jednorazowo dane konta panelu. */
    public function mariadbStatus(): array
    {
        return $this->request('GET', '/system/mariadb');
    }

    /** Zleca instalację MariaDB (usługa roota na węźle); konto panelu tylko z adresu panelu. */
    public function installMariadb(bool $openFirewall): array
    {
        return $this->request('POST', '/system/mariadb', ['open_firewall' => $openFirewall]);
    }

    /** Panel zapisał dane konta — węzeł usuwa je z dysku. */
    public function forgetMariadbCredentials(): array
    {
        return $this->request('DELETE', '/system/mariadb/credentials');
    }

    /**
     * Parametry połączenia WebSocket z konsolą maszyny — dla przekaźnika
     * konsoli, który sam nie zna sekretu węzła. Podpis obejmuje ścieżkę, więc
     * nie da się go użyć do konsoli innej maszyny, i wygasa razem z oknem
     * czasowym agenta.
     *
     * @return array{url: string, headers: array<string, string>, ca_pem: ?string}
     */
    public function consoleConnection(string $uuid, ?string $path = null): array
    {
        $path ??= "/vm/{$uuid}/console";
        $timestamp = (string) time();

        return [
            'url' => preg_replace('#^http#', 'ws', rtrim($this->hypervisor->agent_url, '/')).$path,
            'headers' => [
                'X-VH-Timestamp' => $timestamp,
                'X-VH-Signature' => $this->sign($timestamp, 'GET', $path, ''),
            ],
            'ca_pem' => $this->hypervisor->agent_tls_cert ?: null,
        ];
    }

    /** Konsola aplikacji na żywo (wyjście strumieniem, polecenia, statystyki). */
    public function appConsoleConnection(string $uuid): array
    {
        return $this->consoleConnection($uuid, "/apps/{$uuid}/console");
    }

    // --- operacje (zwracają identyfikator zadania po stronie agenta) --------

    public function createVm(array $payload): string
    {
        return $this->jobId($this->request('POST', '/vm', $payload));
    }

    // --- migracje między węzłami ---------------------------------------------

    public function exportVm(string $uuid): string
    {
        return $this->jobId($this->request('POST', "/vm/{$uuid}/export"));
    }

    /** Ładunek jak createVm + source {url, sha256, size_bytes, start}. */
    public function importVm(array $payload): string
    {
        return $this->jobId($this->request('POST', '/vm/import', $payload, 60));
    }

    public function prepareMigration(array $payload): string
    {
        return $this->jobId($this->request('POST', '/vm/prepare-migration', $payload, 60));
    }

    public function migrateLive(string $uuid, string $host, ?int $bandwidthMbps = null): string
    {
        return $this->jobId($this->request('POST', "/vm/{$uuid}/migrate", array_filter(['host' => $host, 'bandwidth_mbps' => $bandwidthMbps])));
    }

    public function revokeTransfer(string $token): void
    {
        $this->request('DELETE', '/transfer/'.rawurlencode($token));
    }

    /** Adres, pod którym inne węzły pobierają wystawiony dysk. */
    public function transferUrl(string $token): string
    {
        return rtrim((string) $this->hypervisor->agent_url, '/').'/transfer/'.rawurlencode($token);
    }

    /** DR: maszyna z kopii na celu zewnętrznym (ładunek jak createVm + backup). */
    public function recoverVm(array $payload): string
    {
        return $this->jobId($this->request('POST', '/vm/recover', $payload, 60));
    }

    /** DR: zrzut konfiguracji węzła na cel kopii. @param  array{type:string, config:array<string, mixed>}  $target */
    public function drSnapshot(string $key, array $target): string
    {
        return $this->jobId($this->request('POST', '/system/dr-snapshot', ['key' => $key, 'target' => $target]));
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

    public function cpuLimit(string $uuid, ?int $percent): string
    {
        return $this->jobId($this->request('PUT', "/vm/{$uuid}/cpu-limit", ['cpu_limit_percent' => $percent]));
    }

    /** MAC karty maszyny (null = domyślny). Zmiana działa po wyłączeniu i włączeniu maszyny. */
    public function setMac(string $uuid, ?string $mac): string
    {
        return $this->jobId($this->request('PUT', "/vm/{$uuid}/mac", ['mac' => $mac]));
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

    /** Pobranie szablonu kontenera na węzeł z wyprzedzeniem. */
    /** @param  array{sha256?: string, sha512?: string}  $checksum */
    public function downloadTemplate(string $filename, string $url, array $checksum): string
    {
        return $this->jobId($this->request('POST', '/templates/download', ['name' => $filename, 'url' => $url] + $checksum));
    }

    public function prefetchImage(string $alias): string
    {
        return $this->jobId($this->request('POST', '/images/prefetch', ['alias' => $alias]));
    }

    // --- transport ----------------------------------------------------------

    private function jobId(array $response): string
    {
        if (! isset($response['job_id'])) {
            throw new AgentException(
                __('Agent :name nie zwrócił identyfikatora zadania.', ['name' => $this->hypervisor->name])
            );
        }

        return $response['job_id'];
    }

    // --- aplikacje (serwery gier, boty) -----------------------------------------

    public function appsHealth(): array
    {
        return $this->request('GET', '/apps');
    }

    /** Instalacja aplikacji — zwraca identyfikator zadania w kolejce agenta. */
    public function appInstall(array $spec): string
    {
        return $this->jobId($this->request('POST', '/apps', $spec));
    }

    public function appReinstall(string $uuid, array $spec): string
    {
        return $this->jobId($this->request('POST', "/apps/{$uuid}/reinstall", $spec));
    }

    public function appUpdate(string $uuid, array $spec): array
    {
        return $this->request('PUT', "/apps/{$uuid}", $spec);
    }

    public function appDelete(string $uuid): array
    {
        return $this->request('DELETE', "/apps/{$uuid}", timeout: 120);
    }

    public function appStatus(string $uuid): array
    {
        return $this->request('GET', "/apps/{$uuid}/status");
    }

    /** Zatrzymanie czeka na aplikację do 30 s, zanim ją zabije. */
    public function appPower(string $uuid, string $action): array
    {
        return $this->request('POST', "/apps/{$uuid}/power", ['action' => $action], timeout: 75);
    }

    public function appCommand(string $uuid, string $command): array
    {
        return $this->request('POST', "/apps/{$uuid}/command", ['command' => $command]);
    }

    public function appLogs(string $uuid, ?float $since = null, int $tail = 200): array
    {
        return $this->request('POST', "/apps/{$uuid}/logs", ['since' => $since, 'tail' => $tail]);
    }

    /** Operacje na plikach: list, read, write, mkdir, delete, rename, decompress. */
    /** Modpack, loader, plugin albo mod — lista kroków dla agenta (zadanie w kolejce). */
    public function appContent(string $uuid, array $request): string
    {
        return $this->jobId($this->request('POST', "/apps/{$uuid}/content", $request, timeout: 60));
    }

    public function appFiles(string $uuid, string $operation, array $body): array
    {
        return $this->request('POST', "/apps/{$uuid}/files/{$operation}", $body, timeout: 120);
    }

    private function request(string $method, string $path, ?array $body = null, int $timeout = self::TIMEOUT): array
    {
        $payload = $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $timestamp = (string) time();

        $request = Http::timeout($timeout)
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
                __('Hypervisor :name jest nieosiągalny: :getmessage', ['name' => $this->hypervisor->name, 'getmessage' => $e->getMessage()]),
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

        Log::warning(__('Agent odrzucił żądanie'), [
            'hypervisor' => $this->hypervisor->id,
            'request' => "{$method} {$path}",
            'status' => $response->status(),
            'detail' => $detail,
        ]);

        throw new AgentException(
            __('Hypervisor :name odrzucił operację: :detail', ['name' => $this->hypervisor->name, 'detail' => $detail]),
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
        return app(HypervisorEnrollment::class)
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
