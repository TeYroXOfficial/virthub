<?php

namespace App\Domain\Apps;

use App\Jobs\InstallAppJob;
use App\Models\AppEgg;
use App\Models\AppJob;
use App\Models\AppPlan;
use App\Models\AppServer;
use App\Models\AuditLog;
use App\Models\Hypervisor;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Przeniesienie serwera z panelu Pterodactyl.
 *
 * Panel zakłada egg (import z API Pterodactyla), konto klienta (po e-mailu),
 * aplikację z tymi samymi zasobami i zmiennymi, a zamiast skryptu instalacji
 * eggu węzeł pobiera spakowane pliki serwera prosto z Wings.
 */
class PterodactylMigrator
{
    public function __construct(
        private readonly EggImporter $eggs,
        private readonly AppPorts $ports,
        private readonly AppProvisioner $apps,
    ) {}

    /**
     * @param  array<string, mixed>  $server  serwer z API aplikacji Pterodactyla (include=egg,user,allocations)
     * @return array{app: AppServer, password: ?string} hasło, gdy powstało nowe konto klienta
     */
    public function migrate(Pterodactyl $api, array $credentials, array $server, ?Hypervisor $node, bool $stop, User $actor): array
    {
        $egg = $this->egg($api, $server);
        [$owner, $password] = $this->owner($server);

        $limits = $server['limits'] ?? [];
        $memory = (int) ($limits['memory'] ?? 0) ?: 4096;   // 0 = bez limitu w Pterodactylu
        $disk = (int) ($limits['disk'] ?? 0) ?: 20480;
        $cpu = (int) ($limits['cpu'] ?? 0);
        $portCount = max(1, count($server['relationships']['allocations']['data'] ?? []));

        $image = (string) ($server['container']['image'] ?? '');
        if ($image === '' || ! $this->eggs->validImage($image)) {
            $image = $egg->defaultImage();
        } elseif (! in_array($image, $egg->images(), true)) {
            $egg->forceFill(['docker_images' => $egg->images() + ['Pterodactyl' => $image]])->save();
        }

        $known = array_column($egg->variableList(), 'env_variable');
        $environment = array_intersect_key(
            array_map('strval', (array) ($server['container']['environment'] ?? [])),
            array_flip($known),
        );

        $app = DB::transaction(function () use ($server, $egg, $owner, $memory, $disk, $cpu, $portCount, $image, $environment, $node) {
            $node = $node
                ? Hypervisor::query()->lockForUpdate()->findOrFail($node->id)
                : $this->apps->pickNode(new AppPlan(['memory_mb' => $memory, 'disk_mb' => $disk, 'ports' => $portCount]));
            if ($node === null) {
                throw new \DomainException(__('Żaden węzeł nie ma miejsca na :name (:memory MB RAM, :disk MB dysku).', [
                    'name' => $server['name'] ?? '?', 'memory' => $memory, 'disk' => $disk,
                ]));
            }

            $app = AppServer::query()->create([
                'user_id' => $owner->id,
                'hypervisor_id' => $node->id,
                'app_egg_id' => $egg->id,
                'name' => mb_substr((string) ($server['name'] ?? 'Pterodactyl'), 0, 60),
                'docker_image' => $image,
                'environment' => $environment,
                'memory_mb' => $memory,
                'cpu_percent' => $cpu,
                'disk_mb' => $disk,
                'status' => AppServer::STATUS_INSTALLING,
            ]);
            $this->ports->allocate($app, $node, $portCount);

            return $app;
        });

        $job = AppJob::query()->create([
            'app_server_id' => $app->id,
            'user_id' => $actor->id,
            'action' => 'install',
            'payload' => ['pterodactyl' => [
                'url' => $credentials['url'],
                'key' => Crypt::encryptString($credentials['client_key']),
                'identifier' => (string) $server['identifier'],
                'stop' => $stop,
            ]],
            'status' => AppJob::STATUS_QUEUED,
        ]);
        InstallAppJob::dispatch($job->id);

        AuditLog::record('app.migrated', $app, [
            'from' => $credentials['url'], 'identifier' => $server['identifier'] ?? null, 'owner' => $owner->email,
        ], $actor);

        return ['app' => $app, 'password' => $password];
    }

    /**
     * Skrypt instalacji zamiast skryptu eggu: pliki serwera z Wings.
     * Wołane przez InstallAppJob tuż przed zleceniem instalacji węzłowi.
     *
     * @return array{image: string, entrypoint: string, script: string}
     */
    public function installFor(AppJob $job): array
    {
        $source = $job->payload['pterodactyl'];
        $api = $this->api($source);
        $identifier = $source['identifier'];

        if (empty($source['archive'])) {
            if (! empty($source['stop'])) {
                // Spójne pliki: serwer zatrzymany przed pakowaniem (np. świat Minecrafta).
                try {
                    $api->stop($identifier);
                    sleep(10);
                } catch (\Throwable) {
                    // Już wyłączony albo brak uprawnienia — pakujemy mimo to.
                }
            }
            $source['archive'] = $api->compressAll($identifier);
            // Ponowienie zadania (np. węzeł chwilowo nie odpowiadał) nie pakuje drugi raz.
            $job->forceFill(['payload' => ['pterodactyl' => $source] + $job->payload])->save();
        }

        $url = $api->downloadUrl($identifier, $source['archive']);
        $quoted = "'".str_replace("'", "'\\''", $url)."'";

        return [
            'image' => 'ghcr.io/pterodactyl/installers:alpine',
            'entrypoint' => 'ash',
            'script' => <<<SH
#!/bin/ash
# VirtHub: pliki serwera przeniesione z Pterodactyla (zamiast skryptu eggu).
set -eo pipefail
command -v curl >/dev/null 2>&1 || apk add --no-cache curl tar >/dev/null
mkdir -p /mnt/server
cd /mnt/server
echo "[VirtHub] Pobieram i rozpakowuję pliki z Pterodactyla…"
curl -fSL --retry 3 --connect-timeout 30 {$quoted} | tar -xzf - -C /mnt/server
echo "[VirtHub] Pliki przeniesione."
SH,
        ];
    }

    /** Po migracji: archiwum znika z serwera w Pterodactylu, klucz — z panelu. */
    public function cleanup(AppJob $job): void
    {
        $source = $job->payload['pterodactyl'] ?? null;
        if (! is_array($source)) {
            return;
        }
        if (! empty($source['archive']) && ! empty($source['key'])) {
            try {
                $this->api($source)->deleteFile($source['identifier'], $source['archive']);
            } catch (\Throwable $e) {
                Log::warning('Nie udało się usunąć archiwum migracji w Pterodactylu', ['error' => $e->getMessage()]);
            }
        }
        unset($source['key']);
        $job->forceFill(['payload' => ['pterodactyl' => $source] + $job->payload])->save();
    }

    private function api(array $source): Pterodactyl
    {
        return new Pterodactyl($source['url'], '', Crypt::decryptString($source['key']));
    }

    private function egg(Pterodactyl $api, array $server): AppEgg
    {
        $name = (string) ($server['relationships']['egg']['attributes']['name'] ?? '');
        $existing = $name !== '' ? AppEgg::query()->where('source', 'pterodactyl')->where('name', mb_substr($name, 0, 120))->first() : null;
        if ($existing) {
            return $existing;
        }
        $egg = $this->eggs->import($api->egg((int) $server['nest'], (int) $server['egg']));
        $egg->forceFill(['source' => 'pterodactyl'])->save();

        return $egg;
    }

    /** @return array{0: User, 1: ?string} */
    private function owner(array $server): array
    {
        $user = $server['relationships']['user']['attributes'] ?? [];
        $email = strtolower(trim((string) ($user['email'] ?? '')));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \DomainException(__('Serwer :name nie ma właściciela z adresem e-mail.', ['name' => $server['name'] ?? '?']));
        }
        if ($existing = User::query()->where('email', $email)->first()) {
            return [$existing, null];
        }
        $password = Str::password(16, symbols: false);
        $name = trim(($user['first_name'] ?? '').' '.($user['last_name'] ?? '')) ?: ($user['username'] ?? $email);

        return [User::query()->create([
            'name' => mb_substr($name, 0, 100),
            'email' => $email,
            'password' => $password,
            'role' => User::ROLE_CUSTOMER,
        ]), $password];
    }
}
