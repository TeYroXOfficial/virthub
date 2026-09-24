<?php

namespace Database\Seeders;

use App\Domain\Provisioning\IpAllocator;
use App\Models\Hypervisor;
use App\Models\IpPool;
use App\Models\OsTemplate;
use App\Models\User;
use App\Models\VpsPackage;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Dane startowe środowiska developerskiego.
 *
 * Hypervisor wskazuje na agenta uruchomionego lokalnie w trybie mock
 * (`VH_AGENT_DRIVER=mock`), więc cały przepływ — zamówienie, provisioning,
 * sterowanie zasilaniem — da się przejść bez maszyny z KVM.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('Tworzę konta…');

        // email_verified_at nie jest w $fillable, więc updateOrCreate by go
        // pominął — ustawiamy go osobno przez forceFill.
        User::updateOrCreate(
            ['email' => 'admin@virthub.test'],
            [
                'name' => 'Administrator',
                'password' => 'haslo-developerskie',
                'role' => User::ROLE_ADMIN,
            ],
        )->forceFill(['email_verified_at' => now()])->save();

        User::updateOrCreate(
            ['email' => 'klient@virthub.test'],
            [
                'name' => 'Jan Kowalski',
                'password' => 'haslo-developerskie',
                'role' => User::ROLE_CUSTOMER,
            ],
        )->forceFill(['email_verified_at' => now()])->save();

        $this->command->info('Tworzę katalog pakietów…');

        $packages = [
            ['Starter', 1, 2048, 25, 1000, 2900],
            ['Standard', 2, 4096, 50, 2000, 4900],
            ['Business', 4, 8192, 100, 4000, 9900],
            ['Performance', 8, 16384, 200, 8000, 19900],
        ];

        foreach ($packages as [$name, $vcpu, $ram, $disk, $bandwidth, $price]) {
            VpsPackage::updateOrCreate(
                ['slug' => Str::slug($name)],
                [
                    'name' => $name,
                    'vcpu' => $vcpu,
                    'ram_mb' => $ram,
                    'disk_gb' => $disk,
                    'bandwidth_gb' => $bandwidth,
                    'ip_count' => 1,
                    'price_hint_cents' => $price,
                    'currency' => 'PLN',
                    'is_active' => true,
                ],
            );
        }

        $this->command->info('Tworzę szablony systemów…');

        $templates = [
            ['Ubuntu 24.04 LTS', 'ubuntu', '24.04', 'ubuntu-24.04.qcow2', 10],
            ['Ubuntu 22.04 LTS', 'ubuntu', '22.04', 'ubuntu-22.04.qcow2', 10],
            ['Debian 12', 'debian', '12', 'debian-12.qcow2', 10],
            ['AlmaLinux 9', 'almalinux', '9', 'almalinux-9.qcow2', 15],
        ];

        foreach ($templates as [$name, $family, $version, $file, $minDisk]) {
            OsTemplate::updateOrCreate(
                ['image_file' => $file],
                [
                    'name' => $name,
                    'family' => $family,
                    'version' => $version,
                    'min_disk_gb' => $minDisk,
                    'cloud_init_support' => true,
                    'is_active' => true,
                ],
            );
        }

        $this->command->info('Tworzę szablony kontenerów z katalogu…');

        foreach (config('virthub.lxc_catalog') as $entry) {
            OsTemplate::updateOrCreate(
                ['image_file' => $entry['alias'], 'virtualization' => 'lxc'],
                [
                    'name' => $entry['name'],
                    'family' => $entry['family'],
                    'version' => $entry['version'],
                    'min_disk_gb' => 4,
                    'cloud_init_support' => true,
                    'is_active' => true,
                ],
            );
        }

        $this->command->info('Rejestruję lokalny hypervisor (agent w trybie mock)…');

        $hypervisor = Hypervisor::updateOrCreate(
            ['hostname' => 'localhost'],
            [
                'name' => 'dev-node',
                'agent_url' => env('VIRTHUB_DEV_AGENT_URL', 'http://127.0.0.1:8899'),
                // Ten sam token musi trafić do VH_AGENT_TOKEN agenta.
                'agent_token' => env('VIRTHUB_DEV_AGENT_TOKEN', 'dev-token-zmien-w-produkcji'),
                'callback_secret' => env('VIRTHUB_DEV_CALLBACK_SECRET', 'dev-callback-zmien-w-produkcji'),
                'status' => Hypervisor::STATUS_ONLINE,
                'cpu_cores_total' => 32,
                'ram_mb_total' => 65536,
                'disk_gb_total' => 2000,
                'last_seen_at' => now(),
                'accepts_new_servers' => true,
                'bridge' => 'br0',
            ],
        );

        if ($hypervisor->ipPools()->doesntExist()) {
            $this->command->info('Importuję pulę adresów…');

            $pool = IpPool::create([
                'hypervisor_id' => $hypervisor->id,
                'name' => 'Pula developerska',
                'cidr' => '203.0.113.0/24',
                'version' => 4,
                'gateway' => '203.0.113.1',
                'prefix' => 24,
                'nameservers' => ['1.1.1.1', '9.9.9.9'],
            ]);

            $imported = app(IpAllocator::class)->importPool($pool, '203.0.113.10', '203.0.113.60');
            $this->command->info("Zaimportowano {$imported} adresów.");
        }

        $this->command->newLine();
        $this->command->info('Gotowe. Zaloguj się jako:');
        $this->command->line('  admin@virthub.test / haslo-developerskie  (administrator)');
        $this->command->line('  klient@virthub.test / haslo-developerskie (klient)');
        $this->command->newLine();
        $this->command->warn('Hasła są jawne celowo — to dane developerskie. Nie używaj tego seedera w produkcji.');
    }
}
