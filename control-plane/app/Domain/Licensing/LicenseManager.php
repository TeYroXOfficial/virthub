<?php

namespace App\Domain\Licensing;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Licencja instancji VirtHub.
 *
 * Panel wysyła klucz licencji i swoją domenę do serwera licencji, a ten
 * odsyła token podpisany Ed25519: stan licencji, datę ważności i wykupione
 * addony. Panel ufa wyłącznie tokenowi z poprawnym podpisem, wystawionemu
 * dla jego domeny. Token odświeża harmonogram co 12 h; gdy serwer licencji
 * nie odpowiada, ostatni token jest ważny jeszcze przez `grace_days`.
 */
class LicenseManager
{
    public const STATE_NONE = 'none';            // brak klucza

    public const STATE_UNCONFIGURED = 'unconfigured'; // panel bez adresu/klucza publicznego serwera licencji

    public const STATE_VALID = 'valid';

    public const STATE_EXPIRED = 'expired';

    public const STATE_SUSPENDED = 'suspended';

    public const STATE_STALE = 'stale';          // brak kontaktu z serwerem licencji dłużej niż okres łaski

    public const STATE_DOMAIN = 'domain';        // token dla innej domeny

    public const STATE_INVALID = 'invalid';      // zły podpis / uszkodzony token

    private ?array $cached = null;

    public function configured(): bool
    {
        return config('virthub.license.server') !== '' && Signature::publicKey() !== null;
    }

    public function key(): ?string
    {
        return Setting::get('license.key');
    }

    /** Domena panelu, dla której wystawiana jest licencja. */
    public function domain(): string
    {
        return strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
    }

    /**
     * Stan licencji z ostatniego tokenu.
     *
     * @return array{state:string, payload:?array, checked_at:?int, error:?string}
     */
    public function status(): array
    {
        if ($this->cached !== null) {
            return $this->cached;
        }
        $checkedAt = ($v = Setting::get('license.checked_at')) !== null ? (int) $v : null;
        $error = Setting::get('license.error');
        $base = ['payload' => null, 'checked_at' => $checkedAt, 'error' => $error];

        if (! $this->configured()) {
            return $this->cached = ['state' => self::STATE_UNCONFIGURED] + $base;
        }
        if (! $this->key()) {
            return $this->cached = ['state' => self::STATE_NONE] + $base;
        }
        $payload = $this->decode((string) Setting::get('license.token'), (string) Setting::get('license.signature'));
        if ($payload === null) {
            return $this->cached = ['state' => self::STATE_INVALID] + $base;
        }
        $state = match (true) {
            ($payload['domain'] ?? null) !== $this->domain() => self::STATE_DOMAIN,
            ($payload['status'] ?? null) !== 'active' => self::STATE_SUSPENDED,
            ! empty($payload['expires_at']) && strtotime((string) $payload['expires_at']) < now()->getTimestamp() => self::STATE_EXPIRED,
            (int) ($payload['issued_at'] ?? 0) < now()->getTimestamp() - (int) config('virthub.license.grace_days') * 86400 => self::STATE_STALE,
            default => self::STATE_VALID,
        };

        return $this->cached = ['state' => $state, 'payload' => $payload] + $base;
    }

    public function valid(): bool
    {
        return $this->status()['state'] === self::STATE_VALID;
    }

    /** Czy licencja obejmuje addon. */
    public function hasAddon(string $id): bool
    {
        $status = $this->status();

        return $status['state'] === self::STATE_VALID && isset($status['payload']['addons'][$id]);
    }

    /** @return array<string, array{name?:string, version?:string}> */
    public function addons(): array
    {
        return $this->valid() ? (array) ($this->status()['payload']['addons'] ?? []) : [];
    }

    /** Zapisuje klucz i od razu sprawdza go na serwerze licencji. */
    public function activate(string $key, ?User $actor = null): array
    {
        Setting::put(['license.key' => trim($key), 'license.token' => null, 'license.signature' => null]);
        AuditLog::record('license.key_set', null, ['key' => $this->mask(trim($key))], $actor);

        return $this->refresh();
    }

    public function remove(?User $actor = null): void
    {
        Setting::put(['license.key' => null, 'license.token' => null, 'license.signature' => null, 'license.error' => null, 'license.checked_at' => null]);
        $this->cached = null;
        AuditLog::record('license.removed', null, [], $actor);
    }

    /**
     * Pobiera świeży token. Błąd sieci nie kasuje poprzedniego tokenu (okres łaski);
     * odpowiedź serwera licencji „nieważny klucz” — kasuje.
     */
    public function refresh(): array
    {
        $this->cached = null;
        if (! $this->configured() || ! $this->key()) {
            return $this->status();
        }

        try {
            $response = Http::timeout(15)->acceptJson()->post(config('virthub.license.server').'/api/v1/license/verify', [
                'key' => $this->key(),
                'domain' => $this->domain(),
                'panel_version' => $this->panelVersion(),
            ]);
        } catch (ConnectionException $e) {
            return $this->fail(__('Serwer licencji nie odpowiada: :error', ['error' => $e->getMessage()]));
        }

        if (in_array($response->status(), [403, 404, 422], true)) {
            Setting::put(['license.token' => null, 'license.signature' => null]);

            return $this->fail((string) ($response->json('message') ?? __('Serwer licencji odrzucił klucz.')));
        }
        if (! $response->successful()) {
            return $this->fail(__('Serwer licencji zwrócił błąd :status.', ['status' => $response->status()]));
        }

        $token = (string) $response->json('token');
        $signature = (string) $response->json('signature');
        if ($this->decode($token, $signature) === null) {
            return $this->fail(__('Odpowiedź serwera licencji ma nieprawidłowy podpis.'));
        }
        Setting::put(['license.token' => $token, 'license.signature' => $signature, 'license.checked_at' => (string) now()->getTimestamp(), 'license.error' => null]);
        $this->cached = null;

        return $this->status();
    }

    /** Zapytanie do serwera licencji w imieniu tej licencji (katalog, pobieranie addonów). */
    public function request(string $method, string $path, array $data = [], int $timeout = 30): Response
    {
        return Http::timeout($timeout)->acceptJson()
            ->send($method, config('virthub.license.server').$path, ['json' => $data + ['key' => $this->key(), 'domain' => $this->domain()]]);
    }

    public function mask(?string $key): string
    {
        $key = (string) $key;

        return strlen($key) > 8 ? substr($key, 0, 7).str_repeat('•', 6).substr($key, -4) : str_repeat('•', strlen($key));
    }

    private function fail(string $message): array
    {
        Setting::put(['license.error' => $message, 'license.checked_at' => (string) now()->getTimestamp()]);
        $this->cached = null;

        return $this->status();
    }

    /** @return array<string, mixed>|null treść tokenu, gdy podpis jest poprawny */
    private function decode(string $token, string $signature): ?array
    {
        if ($token === '' || ! Signature::verify($token, $signature)) {
            return null;
        }
        try {
            $json = json_decode((string) base64_decode(strtr($token, '-_', '+/'), true), true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return is_array($json) ? $json : null;
    }

    private function panelVersion(): string
    {
        $file = base_path('../VERSION');

        return is_file($file) ? trim((string) file_get_contents($file)) : 'dev';
    }
}
