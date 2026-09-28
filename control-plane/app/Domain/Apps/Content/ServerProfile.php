<?php

namespace App\Domain\Apps\Content;

use App\Domain\Agent\AgentClient;
use App\Domain\Agent\AgentException;
use App\Models\AppServer;
use Illuminate\Support\Facades\Cache;

/**
 * Co działa na serwerze Minecraft: platforma (paper, vanilla, fabric, forge,
 * neoforge, quilt) i wersja gry — od tego zależy zgodność pluginów i modów.
 *
 * Źródła po kolei: zapis w panelu (po instalacji modpacka/loadera), plik
 * `.virthub-version` z instalacji eggu, `version_history.json` starszego
 * Papera, katalog `versions/` (paperclip), zmienna MINECRAFT_VERSION.
 * Znalezioną wersję zapamiętujemy.
 */
class ServerProfile
{
    /** Platformy pluginów (Bukkit API) → loadery w Modrinth. */
    public const PLUGIN_LOADERS = ['paper' => ['paper', 'spigot', 'bukkit'], 'purpur' => ['purpur', 'paper', 'spigot', 'bukkit']];

    public const MOD_LOADERS = ['fabric', 'quilt', 'forge', 'neoforge'];

    public function __construct(private readonly AppServer $app) {}

    public function platform(): ?string
    {
        $stored = $this->app->minecraft['platform'] ?? null;
        if ($stored) {
            return $stored;
        }

        return match ($this->app->egg?->builtin_key) {
            'minecraft-paper' => 'paper',
            'minecraft-vanilla' => 'vanilla',
            default => null, // egg z modami bez zainstalowanego loadera
        };
    }

    /** plugin | mod | null — jaki rodzaj dodatków przyjmuje serwer. */
    public function addonKind(): ?string
    {
        $platform = $this->platform();

        return match (true) {
            isset(self::PLUGIN_LOADERS[$platform]) => 'plugin',
            in_array($platform, self::MOD_LOADERS, true) => 'mod',
            default => null,
        };
    }

    /** @return list<string> loadery do filtrów zgodności */
    public function loaders(): array
    {
        $platform = $this->platform();
        if (isset(self::PLUGIN_LOADERS[$platform])) {
            return self::PLUGIN_LOADERS[$platform];
        }
        // Quilt uruchamia też mody Fabric.
        return match ($platform) {
            'quilt' => ['quilt', 'fabric'],
            null, 'vanilla' => [],
            default => [$platform],
        };
    }

    public function gameVersion(bool $detect = true): ?string
    {
        $stored = $this->app->minecraft['mc'] ?? null;
        if ($stored || ! $detect) {
            return $stored;
        }

        // Nieudane wykrycie (serwer jeszcze nie startował) pamiętamy chwilę —
        // inaczej każde wejście na stronę pytałoby agenta o pliki.
        $miss = "app:{$this->app->id}:mc-miss";
        if (Cache::has($miss)) {
            return null;
        }
        $version = $this->detect();
        if ($version) {
            $this->remember(['mc' => $version]);
        } else {
            Cache::put($miss, true, 120);
        }

        return $version;
    }

    public function modpack(): ?array
    {
        return $this->app->minecraft['modpack'] ?? null;
    }

    /** @param  array<string, mixed>  $data */
    public function remember(array $data): void
    {
        $this->app->forceFill(['minecraft' => array_merge($this->app->minecraft ?? [], $data)])->save();
    }

    private function detect(): ?string
    {
        if ($this->app->hypervisor !== null && $this->app->isReady()) {
            $client = new AgentClient($this->app->hypervisor);
            foreach (['.virthub-version', 'version_history.json'] as $file) {
                try {
                    $content = base64_decode($client->appFiles($this->app->uuid, 'read', ['path' => $file])['content_base64'] ?? '');
                } catch (AgentException) {
                    continue;
                }
                if ($file === '.virthub-version' && preg_match('/^\s*(\d+\.\d+(?:\.\d+)?)\s*$/', $content, $m)) {
                    return $m[1];
                }
                if (preg_match('/\(MC: (\d+\.\d+(?:\.\d+)?)\)/', $content, $m)) {
                    return $m[1];
                }
            }
            // Paperclip rozpakowuje serwer do versions/<wersja gry>/.
            try {
                $names = collect($client->appFiles($this->app->uuid, 'list', ['path' => 'versions'])['entries'] ?? [])
                    ->filter(fn ($e) => ($e['directory'] ?? false) && preg_match('/^\d+\.\d+(\.\d+)?$/', (string) $e['name']))
                    ->pluck('name')->sort('version_compare')->values();
                if ($names->isNotEmpty()) {
                    return (string) $names->last();
                }
            } catch (AgentException) {
                // brak katalogu — serwer jeszcze nie startował
            }
        }

        $env = $this->app->environment['MINECRAFT_VERSION'] ?? null;

        return is_string($env) && preg_match('/^\d+\.\d+(\.\d+)?$/', $env) ? $env : null;
    }
}
