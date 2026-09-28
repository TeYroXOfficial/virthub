<?php

namespace App\Domain\Apps\Content;

/**
 * Serwer Minecraft z loaderem: kroki instalacji (vanilla, Fabric, Quilt,
 * Forge, NeoForge), dobór Javy do wersji gry i uniwersalny skrypt startowy.
 */
class Loaders
{
    public const TYPES = ['vanilla', 'fabric', 'quilt', 'forge', 'neoforge'];

    /** Pliki i katalogi po poprzednim loaderze/paczce — usuwane przy zmianie (świat zostaje). */
    public const SERVER_FILES = [
        'mods', 'config', 'defaultconfigs', 'kubejs', 'scripts', 'libraries', 'resourcepacks', 'packmenu', 'global_packs',
        '.fabric', '.quilt', 'versions', 'server.jar', 'minecraft_server.jar', 'fabric-server-launch.jar',
        'fabric-server-launcher.properties', 'quilt-server-launch.jar', 'run.sh', 'run.bat', 'user_jvm_args.txt',
        'unix_args.txt', 'installer.log', 'forge-installer.jar', 'neoforge-installer.jar', 'quilt-installer.jar',
        'forge-*.jar', 'neoforge-*.jar', 'minecraft_server*.jar', 'fabric-server-*.jar',
    ];

    /** Numer Javy dla wersji gry (1.16 → 8, 1.17–1.20.4 → 17, 1.20.5+ → 21, 26.x → 25). */
    public static function javaFor(string $mc): int
    {
        if (preg_match('/^1\.(\d+)(?:\.(\d+))?/', $mc, $m)) {
            $minor = (int) $m[1];
            $patch = (int) ($m[2] ?? 0);

            return match (true) {
                $minor <= 16 => 8,
                $minor < 20, $minor === 20 && $patch < 5 => 17,
                default => 21,
            };
        }

        return 25; // nowa numeracja (26.1…) — Java 25
    }

    /**
     * Obraz Dockera z eggu dla danej Javy — dokładny albo najbliższy nowszy.
     *
     * @param  array<string, string>  $images  „Java 17” => obraz
     */
    public static function imageFor(array $images, int $java): ?string
    {
        $byVersion = [];
        foreach ($images as $label => $image) {
            if (preg_match('/java[ _-]?(\d+)/i', $label.' '.$image, $m)) {
                $byVersion[(int) $m[1]] = $image;
            }
        }
        ksort($byVersion);
        foreach ($byVersion as $version => $image) {
            if ($version >= $java) {
                return $image;
            }
        }

        return $byVersion ? end($byVersion) : null;
    }

    /**
     * Kroki instalacji loadera (bez usuwania starych plików — to robi wywołujący).
     *
     * @return array{steps: list<array<string, mixed>>, loader_version: ?string}
     */
    public function steps(string $loader, string $mc, ?string $version = null): array
    {
        return match ($loader) {
            'vanilla' => ['steps' => [$this->vanilla($mc)], 'loader_version' => null],
            'fabric' => $this->fabric($mc, $version),
            'quilt' => $this->quilt($mc, $version),
            'forge' => $this->forge($mc, $version),
            'neoforge' => $this->neoforge($mc, $version),
            default => throw new ContentException(__('Nieznany loader: :loader', ['loader' => $loader])),
        };
    }

    /** @return list<array{id: string, type: string, url: string}> wersje gry od Mojang, najnowsze pierwsze */
    public static function mojangVersions(): array
    {
        return ContentHttp::cached('mojang:versions', ContentHttp::DETAILS, fn () => array_values(array_map(
            fn ($v) => ['id' => (string) $v['id'], 'type' => (string) ($v['type'] ?? ''), 'url' => (string) $v['url']],
            ContentHttp::json(ContentHttp::client()->get('https://piston-meta.mojang.com/mc/game/version_manifest_v2.json'), 'Mojang')['versions'] ?? [],
        )));
    }

    /** @return array<string, mixed> */
    public function vanilla(string $mc, string $path = 'server.jar'): array
    {
        $entry = collect(self::mojangVersions())->firstWhere('id', $mc);
        if ($entry === null) {
            throw new ContentException(__('Mojang nie zna wersji Minecrafta :version.', ['version' => $mc]));
        }
        $server = ContentHttp::cached("mojang:server:{$mc}", ContentHttp::IMMUTABLE, fn () => ContentHttp::json(
            ContentHttp::client()->get($entry['url']), 'Mojang',
        )['downloads']['server'] ?? null);
        if (! $server) {
            throw new ContentException(__('Wersja :version nie ma serwera od Mojang.', ['version' => $mc]));
        }

        return ['op' => 'download', 'url' => $server['url'], 'path' => $path, 'sha1' => $server['sha1'], 'size' => (int) $server['size'],
            'label' => __('Serwer Minecraft :version', ['version' => $mc])];
    }

    private function fabric(string $mc, ?string $version): array
    {
        $loaders = ContentHttp::cached("fabric:loaders:{$mc}", ContentHttp::LISTS, fn () => ContentHttp::json(
            ContentHttp::client()->get('https://meta.fabricmc.net/v2/versions/loader/'.rawurlencode($mc)), 'Fabric',
        ));
        if ($loaders === []) {
            throw new ContentException(__('Fabric nie wspiera Minecrafta :version.', ['version' => $mc]));
        }
        $version ??= collect($loaders)->first(fn ($l) => $l['loader']['stable'] ?? false)['loader']['version'] ?? $loaders[0]['loader']['version'];
        $installers = ContentHttp::cached('fabric:installers', ContentHttp::LISTS, fn () => ContentHttp::json(
            ContentHttp::client()->get('https://meta.fabricmc.net/v2/versions/installer'), 'Fabric',
        ));
        $installer = collect($installers)->firstWhere('stable', true)['version'] ?? $installers[0]['version'];

        return ['steps' => [
            $this->vanilla($mc),
            ['op' => 'download', 'path' => 'fabric-server-launch.jar',
                'url' => 'https://meta.fabricmc.net/v2/versions/loader/'.rawurlencode($mc).'/'.rawurlencode($version).'/'.rawurlencode($installer).'/server/jar',
                'label' => "Fabric {$version}"],
        ], 'loader_version' => $version];
    }

    private function quilt(string $mc, ?string $version): array
    {
        $loaders = ContentHttp::cached("quilt:loaders:{$mc}", ContentHttp::LISTS, fn () => ContentHttp::json(
            ContentHttp::client()->get('https://meta.quiltmc.org/v3/versions/loader/'.rawurlencode($mc)), 'Quilt',
        ));
        if ($loaders === []) {
            throw new ContentException(__('Quilt nie wspiera Minecrafta :version.', ['version' => $mc]));
        }
        $version ??= collect($loaders)->first(fn ($l) => ! str_contains($l['loader']['version'], 'beta'))['loader']['version'] ?? $loaders[0]['loader']['version'];
        $xml = ContentHttp::cached('quilt:installer', ContentHttp::LISTS, fn () => ContentHttp::client()
            ->get('https://maven.quiltmc.org/repository/release/org/quiltmc/quilt-installer/maven-metadata.xml')->body());
        if (! preg_match('#<release>([^<]+)</release>#', $xml, $m)) {
            throw new ContentException(__('Nie udało się ustalić wersji instalatora Quilt.'));
        }
        $installer = $m[1];

        return ['steps' => [
            ['op' => 'download', 'path' => 'quilt-installer.jar',
                'url' => "https://maven.quiltmc.org/repository/release/org/quiltmc/quilt-installer/{$installer}/quilt-installer-{$installer}.jar",
                'label' => "Quilt {$version}"],
            ['op' => 'java', 'args' => ['-jar', 'quilt-installer.jar', 'install', 'server', $mc, $version, '--download-server', '--install-dir=/home/container'],
                'label' => __('Instalator Quilt…')],
            ['op' => 'delete', 'paths' => ['quilt-installer.jar']],
        ], 'loader_version' => $version];
    }

    private function forge(string $mc, ?string $version): array
    {
        if ($version === null) {
            $promos = ContentHttp::cached('forge:promotions', ContentHttp::LISTS, fn () => ContentHttp::json(
                ContentHttp::client()->get('https://files.minecraftforge.net/net/minecraftforge/forge/promotions_slim.json'), 'Forge',
            ))['promos'] ?? [];
            $version = $promos["{$mc}-recommended"] ?? $promos["{$mc}-latest"] ?? null;
            if ($version === null) {
                throw new ContentException(__('Forge nie wspiera Minecrafta :version.', ['version' => $mc]));
            }
        }
        // Stare wydania (1.7.10–1.9) mają wersję gry także na końcu nazwy.
        $full = preg_match('/^1\.(7|8|9)(\.|$)/', $mc) && ! str_ends_with($version, "-{$mc}") ? "{$mc}-{$version}-{$mc}" : "{$mc}-{$version}";
        $url = "https://maven.minecraftforge.net/net/minecraftforge/forge/{$full}/forge-{$full}-installer.jar";

        return ['steps' => [
            ['op' => 'download', 'path' => 'forge-installer.jar', 'url' => $url, 'sha1' => $this->mavenSha1($url), 'label' => "Forge {$version}"],
            ['op' => 'java', 'args' => ['-jar', 'forge-installer.jar', '--installServer'], 'label' => __('Instalator Forge (pobiera biblioteki, to potrwa)…')],
            ['op' => 'delete', 'paths' => ['forge-installer.jar', 'forge-installer.jar.log', 'installer.log', 'run.bat']],
        ], 'loader_version' => $version];
    }

    private function neoforge(string $mc, ?string $version): array
    {
        // 1.20.1: NeoForge to jeszcze artefakt „forge” w repozytorium neoforged.
        $legacy = $mc === '1.20.1';
        if ($version === null) {
            // Metadane repozytorium NeoForge obejmują tylko najnowszą serię — pełną
            // listę z przypisaniem do wersji gry daje meta Prism Launchera.
            $index = ContentHttp::cached('neoforge:index', ContentHttp::LISTS, fn () => ['versions' => array_map(
                fn ($v) => ['version' => $v['version'], 'type' => $v['type'] ?? 'release', 'requires' => $v['requires'] ?? []],
                ContentHttp::json(ContentHttp::client()->get('https://meta.prismlauncher.org/v1/net.neoforged/index.json'), 'NeoForge')['versions'] ?? [],
            )]);
            $matching = array_values(array_filter($index['versions'] ?? [], fn ($v) => collect($v['requires'] ?? [])
                ->contains(fn ($r) => ($r['uid'] ?? '') === 'net.minecraft' && ($r['equals'] ?? '') === $mc)));
            $pick = collect($matching)->first(fn ($v) => ($v['type'] ?? 'release') === 'release' && ! str_contains($v['version'], 'beta'))
                ?? ($matching[0] ?? null);
            if ($pick === null) {
                throw new ContentException(__('NeoForge nie wspiera Minecrafta :version.', ['version' => $mc]));
            }
            $version = (string) $pick['version'];
            if ($legacy && str_starts_with($version, '1.20.1-')) {
                $version = substr($version, strlen('1.20.1-'));
            }
        }
        $url = $legacy
            ? "https://maven.neoforged.net/releases/net/neoforged/forge/1.20.1-{$version}/forge-1.20.1-{$version}-installer.jar"
            : "https://maven.neoforged.net/releases/net/neoforged/neoforge/{$version}/neoforge-{$version}-installer.jar";

        return ['steps' => [
            ['op' => 'download', 'path' => 'neoforge-installer.jar', 'url' => $url, 'sha1' => $this->mavenSha1($url), 'label' => "NeoForge {$version}"],
            ['op' => 'java', 'args' => ['-jar', 'neoforge-installer.jar', '--installServer'], 'label' => __('Instalator NeoForge (pobiera biblioteki, to potrwa)…')],
            ['op' => 'delete', 'paths' => ['neoforge-installer.jar', 'neoforge-installer.jar.log', 'installer.log', 'run.bat']],
        ], 'loader_version' => $version];
    }

    private function mavenSha1(string $url): ?string
    {
        $sha1 = ContentHttp::cached('maven:sha1:'.md5($url), ContentHttp::IMMUTABLE, function () use ($url) {
            $response = ContentHttp::client()->get($url.'.sha1');
            if ($response->status() === 404) {
                throw new ContentException(__('Nie ma takiej wersji loadera (:url).', ['url' => basename($url)]));
            }

            return trim(substr($response->body(), 0, 40));
        });

        return preg_match('/^[0-9a-f]{40}$/', (string) $sha1) ? $sha1 : null;
    }

    /** Uniwersalny start: Forge/NeoForge (argumenty z bibliotek), Fabric, Quilt, stary Forge, vanilla. */
    public static function startScript(): string
    {
        return <<<'SH'
#!/bin/bash
# VirtHub: start serwera Minecraft z loaderem (Forge, NeoForge, Fabric, Quilt, vanilla).
# Plik jest nadpisywany przy instalacji modpacka albo loadera.
JAVA_OPTS="-Xms128M -XX:MaxRAMPercentage=95.0 -Dterminal.jline=false -Dterminal.ansi=true"
[ -f user_jvm_args.txt ] && JAVA_OPTS="$JAVA_OPTS @user_jvm_args.txt"
ARGS=$(find libraries -name unix_args.txt 2>/dev/null | head -n 1)
if [ -n "$ARGS" ]; then
    exec java $JAVA_OPTS @"$ARGS" nogui "$@"
fi
for JAR in fabric-server-launch.jar quilt-server-launch.jar; do
    [ -f "$JAR" ] && exec java $JAVA_OPTS -jar "$JAR" nogui "$@"
done
JAR=$(ls forge-*.jar neoforge-*.jar 2>/dev/null | grep -v installer | head -n 1)
[ -n "$JAR" ] && exec java $JAVA_OPTS -jar "$JAR" nogui "$@"
if [ -f server.jar ]; then
    exec java $JAVA_OPTS -jar server.jar nogui "$@"
fi
echo "[VirtHub] Brak serwera do uruchomienia — zainstaluj modpack albo loader w zakładce Modpacki."
exit 1
SH;
    }
}
