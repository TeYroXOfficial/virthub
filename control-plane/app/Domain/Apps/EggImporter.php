<?php

namespace App\Domain\Apps;

use App\Models\AppEgg;
use Illuminate\Validation\ValidationException;

/**
 * Import eggów w formacie Pterodactyla (PTDL_v1 i PTDL_v2).
 *
 * Dzięki temu działają setki gotowych eggów społeczności (pelican-eggs,
 * parkervcp/eggs) — te same obrazy yolks i te same skrypty instalacyjne.
 * Z eggu bierzemy to, co agent potrafi wykonać: obrazy, polecenie startowe,
 * sposób zatrzymania, pliki konfiguracyjne (parsery properties/ini/file/json/
 * yaml), skrypt instalacyjny i zmienne.
 */
class EggImporter
{
    /** Katalog eggów wbudowanych w panel. */
    public static function builtinPath(): string
    {
        return resource_path('eggs');
    }

    /** @return array<string, mixed> atrybuty modelu AppEgg */
    public function parse(array $egg, ?string $category = null): array
    {
        $version = $egg['meta']['version'] ?? null;
        if (! in_array($version, ['PTDL_v1', 'PTDL_v2'], true)) {
            $this->fail(__('To nie jest egg Pterodactyla (brak meta.version PTDL_v1/PTDL_v2).'));
        }

        $name = trim((string) ($egg['name'] ?? ''));
        $startup = trim((string) ($egg['startup'] ?? ''));
        if ($name === '' || $startup === '') {
            $this->fail(__('Egg musi mieć nazwę i polecenie startowe.'));
        }

        $images = $this->images($egg);
        if ($images === []) {
            $this->fail(__('Egg nie ma żadnego obrazu Dockera.'));
        }

        $config = $egg['config'] ?? [];
        $install = $egg['scripts']['installation'] ?? [];
        $script = is_string($install['script'] ?? null) ? str_replace("\r\n", "\n", $install['script']) : null;

        if ($reason = self::forbiddenReason($name, $startup, $script, $images, $egg['description'] ?? null)) {
            $this->fail(__('Tego eggu nie można zaimportować: :reason. Aplikacje służą do serwerów gier i botów, a nie do uruchamiania systemów (PteroVM i podobne), koparek czy zdalnych powłok.', ['reason' => $reason]));
        }

        return [
            'name' => mb_substr($name, 0, 120),
            'category' => $category ?: ($egg['virthub_category'] ?? $this->guessCategory($egg)),
            'description' => is_string($egg['description'] ?? null) ? mb_substr($egg['description'], 0, 2000) : null,
            'author' => is_string($egg['author'] ?? null) ? mb_substr($egg['author'], 0, 190) : null,
            'docker_images' => $images,
            'startup' => $startup,
            'stop_command' => mb_substr((string) ($this->decode($config['stop'] ?? null) ?? '^C'), 0, 200) ?: '^C',
            'startup_done' => $this->startupDone($config['startup'] ?? null),
            'config_files' => $this->configFiles($config['files'] ?? null),
            'install_image' => $script ? ($install['container'] ?? 'ghcr.io/pterodactyl/installers:alpine') : null,
            'install_entrypoint' => $script ? ($install['entrypoint'] ?? 'ash') : null,
            'install_script' => $script,
            'variables' => $this->variables($egg['variables'] ?? []),
            'features' => array_values(array_filter((array) ($egg['features'] ?? []), 'is_string')),
        ];
    }

    public function import(array $egg, ?string $category = null, ?string $builtinKey = null): AppEgg
    {
        $attributes = $this->parse($egg, $category);
        $attributes['source'] = $builtinKey ? 'builtin' : 'import';

        if ($builtinKey) {
            return AppEgg::query()->updateOrCreate(['builtin_key' => $builtinKey], $attributes);
        }

        return AppEgg::query()->create($attributes);
    }

    /** Wgrywa (albo odświeża) eggi wbudowane. Zwraca liczbę eggów. */
    public function importBuiltin(): int
    {
        $count = 0;
        foreach (glob(self::builtinPath().'/*.json') ?: [] as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            if (is_array($data)) {
                $this->import($data, null, basename($file, '.json'));
                $count++;
            }
        }

        return $count;
    }

    /** @return array<string, string> */
    private function images(array $egg): array
    {
        $images = [];
        if (is_array($egg['docker_images'] ?? null)) {
            foreach ($egg['docker_images'] as $label => $image) {
                if (is_string($image) && $this->validImage($image)) {
                    $images[is_string($label) ? $label : $image] = $image;
                }
            }
        } elseif (is_array($egg['images'] ?? null)) {
            foreach ($egg['images'] as $image) {
                if (is_string($image) && $this->validImage($image)) {
                    $images[$image] = $image;
                }
            }
        } elseif (is_string($egg['image'] ?? null) && $this->validImage($egg['image'])) {
            $images[$egg['image']] = $egg['image'];
        }

        return $images;
    }

    public function validImage(string $image): bool
    {
        return (bool) preg_match('/^[a-z0-9][A-Za-z0-9._\/:@-]{0,254}$/', $image);
    }

    /** Pola config.* w eggach bywają JSON-em zapisanym jako napis. */
    private function decode(mixed $value): mixed
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
        }

        return $value;
    }

    private function startupDone(mixed $startup): ?string
    {
        $startup = $this->decode($startup);
        $done = is_array($startup) ? ($startup['done'] ?? null) : null;
        if (is_array($done)) {
            $done = $done[0] ?? null;
        }

        return is_string($done) && $done !== '' ? mb_substr($done, 0, 200) : null;
    }

    /** @return list<array{file: string, parser: string, find: array<string, string>}> */
    private function configFiles(mixed $files): array
    {
        $files = $this->decode($files);
        if (! is_array($files)) {
            return [];
        }

        $result = [];
        foreach ($files as $file => $spec) {
            if (! is_string($file) || ! is_array($spec) || str_contains($file, '..')) {
                continue;
            }
            $parser = $spec['parser'] ?? 'file';
            if (! in_array($parser, ['properties', 'file', 'ini', 'json', 'yaml'], true)) {
                continue; // xml i inne — agent ich nie obsługuje
            }
            $find = [];
            foreach ((array) ($spec['find'] ?? []) as $key => $value) {
                // Wartości obiektowe (dopasowania warunkowe) i klucze z
                // symbolami wieloznacznymi pomijamy — agent ustawia proste pary.
                if (is_string($key) && (is_string($value) || is_int($value)) && ! str_contains($key, '*')) {
                    $find[$key] = (string) $value;
                }
            }
            if ($find !== []) {
                $result[] = ['file' => ltrim($file, '/'), 'parser' => $parser, 'find' => $find];
            }
        }

        return $result;
    }

    /** @return list<array<string, mixed>> */
    private function variables(mixed $variables): array
    {
        $result = [];
        foreach ((array) $variables as $var) {
            if (! is_array($var) || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', (string) ($var['env_variable'] ?? ''))) {
                continue;
            }
            $result[] = [
                'name' => mb_substr((string) ($var['name'] ?? $var['env_variable']), 0, 120),
                'description' => mb_substr((string) ($var['description'] ?? ''), 0, 1000),
                'env_variable' => $var['env_variable'],
                'default_value' => (string) ($var['default_value'] ?? ''),
                'user_viewable' => (bool) ($var['user_viewable'] ?? true),
                'user_editable' => (bool) ($var['user_editable'] ?? true),
                'rules' => is_array($var['rules'] ?? null) ? implode('|', $var['rules']) : (string) ($var['rules'] ?? 'nullable|string'),
            ];
        }

        return $result;
    }

    private function guessCategory(array $egg): string
    {
        $text = strtolower(($egg['name'] ?? '').' '.($egg['description'] ?? ''));

        return match (true) {
            str_contains($text, 'bot') || str_contains($text, 'discord') => 'bot',
            (bool) preg_match('/\b(nginx|apache|php|web|api|database|mysql|mariadb|postgres|redis|mongo)\b/', $text) => 'web',
            default => 'game',
        };
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['egg' => $message]);
    }

    /**
     * Eggi „VPS” (PteroVM, Pterodactyl-VPS-Egg itp.) uruchamiają w kontenerze
     * cały system przez proot albo QEMU — to nadużycie hostingu aplikacji.
     * Ten sam katalog nazw co ochrona w agencie (agent/app_guard.py).
     *
     * @param  list<string>|array<string, string>  $images
     */
    public static function forbiddenReason(string $name, string $startup, ?string $script, array $images, mixed $description = null): ?string
    {
        $haystack = mb_strtolower(implode("\n", [$name, $startup, (string) $script, implode(' ', $images), is_string($description) ? $description : '']));
        $rules = [
            'PteroVM / VPS w kontenerze' => '/pterovm|pterodactyl-vps|vps[-_ ]?egg|\bfree\s*vps\b/',
            'proot / udocker' => '/\b(proot|udocker|fakechroot)\b/',
            'QEMU' => '/\bqemu-(system|img|x86_64|aarch64)/',
            'koparka kryptowalut' => '/\b(xmrig|xmr-stak|cpuminer|minerd|nbminer|lolminer|t-rex|srbminer|phoenixminer)\b|stratum\+(tcp|ssl|tls):\/\//',
            'zdalna powłoka' => '/\b(tmate|ttyd|gotty|shellinabox|sshx|upterm)\b|openssh-server|\bsshd\b/',
        ];

        foreach ($rules as $label => $pattern) {
            if (preg_match($pattern, $haystack)) {
                return $label;
            }
        }

        return null;
    }
}
