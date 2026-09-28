<?php

namespace App\Domain\Apps;

use App\Models\AppServer;

/**
 * Specyfikacja aplikacji dla agenta — jedno miejsce, w którym model panelu
 * spotyka się z kontraktem agenta (AppSpec).
 */
class AppPayload
{
    public static function spec(AppServer $app): array
    {
        $app->loadMissing(['egg', 'allocations']);
        $egg = $app->egg;
        $env = $app->variableValues();
        $primary = $app->allocations->first();

        $placeholders = [
            '{{server.build.default.port}}' => (string) ($primary?->port ?? ''),
            '{{server.allocations.default.port}}' => (string) ($primary?->port ?? ''),
            '{{server.build.default.ip}}' => '0.0.0.0',
            '{{server.allocations.default.ip}}' => '0.0.0.0',
            '{{server.build.memory}}' => (string) $app->memory_mb,
            '{{server.build.disk}}' => (string) $app->disk_mb,
        ];
        foreach ($env as $key => $value) {
            $placeholders["{{server.build.env.{$key}}}"] = $value;
            $placeholders["{{env.{$key}}}"] = $value;
        }

        $configFiles = [];
        foreach ($egg->config_files ?? [] as $file) {
            $replace = [];
            foreach ($file['find'] ?? [] as $key => $value) {
                $resolved = strtr((string) $value, $placeholders);
                // Nieznany placeholder zostawiłby w pliku „{{…}}" — pomijamy klucz.
                if (! str_contains($resolved, '{{')) {
                    $replace[$key] = $resolved;
                }
            }
            if ($replace !== []) {
                $configFiles[] = ['file' => $file['file'], 'parser' => $file['parser'], 'replace' => $replace];
            }
        }

        return [
            'uuid' => $app->uuid,
            'image' => $app->docker_image,
            'startup' => $egg->startup,
            'stop' => $egg->stop_command ?: '^C',
            'environment' => $env,
            'memory_mb' => $app->memory_mb,
            'cpu_percent' => $app->cpu_percent,
            'disk_mb' => $app->disk_mb,
            'allocations' => $app->allocations->map(fn ($a) => ['port' => $a->port])->values()->all(),
            'config_files' => $configFiles,
            'install' => $egg->install_script ? [
                'image' => $egg->install_image ?: 'ghcr.io/pterodactyl/installers:alpine',
                'entrypoint' => $egg->install_entrypoint ?: 'ash',
                'script' => $egg->install_script,
            ] : null,
        ];
    }

    /** Polecenie startowe z podstawionymi zmiennymi — do podglądu w panelu. */
    public static function startupPreview(AppServer $app): string
    {
        $startup = $app->egg?->startup ?? '';
        $values = $app->variableValues() + [
            'SERVER_MEMORY' => (string) $app->memory_mb,
            'SERVER_PORT' => (string) ($app->primaryAllocation()?->port ?? ''),
            'SERVER_IP' => '0.0.0.0',
        ];

        return preg_replace_callback('/\{\{([A-Za-z0-9_]+)\}\}/', fn ($m) => $values[$m[1]] ?? $m[0], $startup);
    }
}
