<?php

namespace App\Domain\Apps\Databases;

use App\Domain\Agent\AgentClient;
use App\Domain\Agent\AgentException;
use App\Models\AuditLog;
use App\Models\DatabaseHost;
use App\Models\Hypervisor;
use App\Models\Setting;
use App\Models\User;

/**
 * „Zainstaluj MariaDB na węźle” — jednym kliknięciem.
 *
 * Panel zleca instalację agentowi (wykonuje ją usługa roota na węźle), a
 * potem odpytuje stan: przy stronie Bazy danych i co minutę z harmonogramu.
 * Po zakończeniu sam dodaje serwer baz z kontem panelu (dostępnym tylko z
 * adresu panelu), sprawdza połączenie i każe węzłowi usunąć hasło z dysku.
 */
class NodeDatabaseInstaller
{
    private const PREFIX = 'mariadb_install.';

    public function __construct(private readonly DatabaseServer $server) {}

    public function start(Hypervisor $node, bool $openFirewall, ?User $actor = null): void
    {
        (new AgentClient($node))->installMariadb($openFirewall);
        $this->remember($node, ['state' => 'queued', 'message' => __('Zlecono instalację — trwa zwykle 1–3 minuty.')]);
        AuditLog::record('database_host.install_requested', $node, ['open_firewall' => $openFirewall], $actor);
    }

    /** @return array<int, array{state:string, message:?string, at:int, host_id?:int}> instalacje według węzła */
    public function all(): array
    {
        $out = [];
        foreach (Setting::allValues() as $key => $value) {
            if (str_starts_with($key, self::PREFIX) && ($data = json_decode((string) $value, true))) {
                $out[(int) substr($key, strlen(self::PREFIX))] = $data;
            }
        }
        ksort($out);

        return $out;
    }

    public function pending(): bool
    {
        return collect($this->all())->contains(fn ($i) => in_array($i['state'], ['queued', 'running'], true));
    }

    /** Odpytuje węzły z trwającą instalacją. */
    public function syncPending(): void
    {
        foreach ($this->all() as $nodeId => $install) {
            if (in_array($install['state'], ['queued', 'running'], true) && ($node = Hypervisor::query()->find($nodeId))) {
                $this->sync($node);
            }
        }
    }

    public function sync(Hypervisor $node): void
    {
        $agent = new AgentClient($node);
        try {
            $status = $agent->mariadbStatus();
        } catch (AgentException $e) {
            return; // węzeł chwilowo nieosiągalny — spróbujemy przy następnym odpytaniu
        }

        $state = (string) ($status['state'] ?? 'idle');
        if (in_array($state, ['failed', 'stalled'], true)) {
            $this->remember($node, ['state' => 'failed', 'message' => (string) ($status['message'] ?? __('Instalacja nie powiodła się.'))]);

            return;
        }
        if ($state !== 'done') {
            $this->remember($node, ['state' => $state === 'running' ? 'running' : 'queued', 'message' => $status['message'] ?? null]);

            return;
        }
        $creds = $status['credentials'] ?? null;
        if (! is_array($creds) || empty($creds['password'])) {
            // Dane już odebrane (np. równoległe odpytanie) albo usunięte.
            $this->remember($node, ['state' => 'failed', 'message' => __('Węzeł nie przekazał danych konta — zleć instalację ponownie.')]);

            return;
        }

        $host = $this->saveHost($node, $creds, (string) ($status['version'] ?? ''));
        try {
            $agent->forgetMariadbCredentials();
        } catch (AgentException) {
            // Plik zostaje tylko na węźle (dostępny dla agenta) — nie blokuje dodania serwera.
        }
    }

    private function saveHost(Hypervisor $node, array $creds, string $version): DatabaseHost
    {
        $agentHost = trim((string) parse_url((string) $node->agent_url, PHP_URL_HOST), '[]');
        // Panel na tym samym serwerze co węzeł — konto przyjmuje połączenia z 127.0.0.1.
        $connect = ($creds['allowed_from'] ?? null) === '127.0.0.1' ? '127.0.0.1' : ($agentHost ?: (string) $node->publicAddress());

        $host = DatabaseHost::query()->firstOrNew(['hypervisor_id' => $node->id, 'username' => (string) $creds['username']]);
        $host->fill([
            'name' => $host->exists ? $host->name : 'MariaDB — '.$node->name,
            'host' => $connect,
            'port' => (int) ($creds['port'] ?? 3306),
            'public_host' => $node->publicAddress(),
        ]);
        $host->setSecret((string) $creds['password']);

        try {
            $this->server->version($host);
            $host->is_active = true;
            $message = __('MariaDB :version gotowa — serwer baz dodany.', ['version' => $version]);
            $result = 'done';
        } catch (DatabaseException $e) {
            // Zapisujemy wyłączony — dane konta nie przepadną, administrator poprawi zaporę i włączy serwer.
            $host->is_active = false;
            $message = __('MariaDB zainstalowana, ale panel nie może się połączyć (:error). Sprawdź zaporę węzła (port :port) i włącz serwer.', ['error' => $e->getMessage(), 'port' => $host->port]);
            $result = 'warning';
        }
        $host->save();
        $this->remember($node, ['state' => $result, 'message' => $message, 'host_id' => $host->id]);
        AuditLog::record('database_host.installed', $host, ['node' => $node->name, 'active' => $host->is_active]);

        return $host;
    }

    public function dismiss(Hypervisor|int $node): void
    {
        Setting::put([self::PREFIX.($node instanceof Hypervisor ? $node->id : $node) => null]);
    }

    private function remember(Hypervisor $node, array $data): void
    {
        Setting::put([self::PREFIX.$node->id => json_encode($data + ['at' => time()], JSON_UNESCAPED_UNICODE)]);
    }
}
