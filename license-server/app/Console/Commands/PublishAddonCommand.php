<?php

namespace App\Console\Commands;

use App\Licensing\AddonPublisher;
use App\Models\Addon;
use Illuminate\Console\Command;

class PublishAddonCommand extends Command
{
    protected $signature = 'license:publish {zip : paczka addonu} {--name= : nazwa przy pierwszej publikacji} {--changelog=}';

    protected $description = 'Podpisuje i publikuje wersję addonu (id i wersja z addon.json)';

    public function handle(AddonPublisher $publisher): int
    {
        $zip = (string) $this->argument('zip');
        $manifest = json_decode((string) @file_get_contents('zip://'.realpath($zip).'#addon.json'), true);
        if (! is_array($manifest) || empty($manifest['id'])) {
            $this->error('Nie mogę odczytać addon.json z paczki.');

            return self::FAILURE;
        }
        $addon = Addon::query()->firstOrCreate(['slug' => $manifest['id']], ['name' => $this->option('name') ?: ($manifest['name'] ?? $manifest['id'])]);
        try {
            $version = $publisher->publish($addon, $zip, $this->option('changelog'));
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info("Opublikowano {$addon->slug} {$version->version} (sha256 {$version->sha256}).");

        return self::SUCCESS;
    }
}
