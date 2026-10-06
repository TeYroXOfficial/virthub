<?php

namespace Tests\Feature;

use App\Domain\External\CreateRequest;
use App\Domain\External\ProviderDriver;
use App\Domain\External\ProviderException;
use App\Domain\External\RemoteServer;

/** Atrapa dostawcy chmury: maszyny w pamięci, log wywołań do sprawdzania w testach. */
class FakeProviderDriver implements ProviderDriver
{
    /** @var array<string, array<string, mixed>> */
    public static array $vms = [];

    /** @var list<string> */
    public static array $calls = [];

    public static bool $failCreate = false;

    public function __construct(private readonly array $credentials = []) {}

    public static function reset(): void
    {
        self::$vms = [];
        self::$calls = [];
        self::$failCreate = false;
    }

    public static function credentialFields(): array
    {
        return ['token' => ['label' => 'API token', 'secret' => true]];
    }

    public function test(): string
    {
        if (($this->credentials['token'] ?? '') !== 'good-token') {
            throw new ProviderException('401 Unauthorized');
        }

        return 'team Acme';
    }

    public function locations(): array
    {
        return [['id' => 'syd', 'name' => 'Sydney'], ['id' => 'waw', 'name' => 'Warszawa']];
    }

    public function plans(): array
    {
        return [
            ['id' => 'std', 'name' => 'Standard', 'cpu' => null, 'ram_mb' => null, 'disk_gb' => null, 'configurable' => true,
                'max_cpu' => 8, 'max_ram_mb' => 16384, 'max_disk_gb' => 200, 'locations' => ['syd', 'waw']],
            ['id' => 'fixed-1', 'name' => 'Fixed 1', 'cpu' => 1, 'ram_mb' => 1024, 'disk_gb' => 25, 'configurable' => false,
                'max_cpu' => null, 'max_ram_mb' => null, 'max_disk_gb' => null, 'locations' => ['syd']],
        ];
    }

    public function images(): array
    {
        return [['id' => '11', 'name' => 'Debian 12', 'family' => 'debian'], ['id' => '12', 'name' => 'Ubuntu 24.04', 'family' => 'ubuntu']];
    }

    public function create(CreateRequest $request): string
    {
        self::$calls[] = 'create:'.$request->plan.':'.$request->location.':'.$request->image.':'.$request->cpu.'/'.$request->ramMb.'/'.$request->diskGb;
        if (self::$failCreate) {
            throw new ProviderException('402 Payment required');
        }
        $id = 'vm-'.(count(self::$vms) + 1);
        self::$vms[$id] = ['status' => RemoteServer::BUILDING, 'ipv4' => null, 'image' => $request->image, 'rdns' => []];

        return $id;
    }

    /** Test: dostawca skończył budowę. */
    public static function finish(string $id): void
    {
        self::$vms[$id]['status'] = RemoteServer::RUNNING;
        self::$vms[$id]['ipv4'] = '203.0.113.50';
        self::$vms[$id]['password'] = 'Root-Pass-1';
    }

    public function get(string $id): RemoteServer
    {
        $vm = self::$vms[$id] ?? ['status' => RemoteServer::DELETED];

        return new RemoteServer($id, $vm['status'], $vm['ipv4'] ?? null, null, $vm['password'] ?? null, 2, 2048, 40);
    }

    public function power(string $id, string $action): void
    {
        self::$calls[] = 'power:'.$id.':'.$action;
        self::$vms[$id]['status'] = $action === 'stop' ? RemoteServer::STOPPED : RemoteServer::RUNNING;
    }

    public function reinstall(string $id, string $image): void
    {
        self::$calls[] = 'reinstall:'.$id.':'.$image;
        self::$vms[$id]['image'] = $image;
        self::$vms[$id]['password'] = 'New-Pass-2';
    }

    public function destroy(string $id): void
    {
        self::$calls[] = 'destroy:'.$id;
        unset(self::$vms[$id]);
    }

    public function console(string $id): string
    {
        return 'https://console.provider.test/vnc/'.$id.'?token=abc';
    }

    public function setRdns(string $id, string $ip, ?string $hostname): void
    {
        self::$calls[] = 'rdns:'.$id.':'.$ip.':'.($hostname ?? '-');
    }

    public function rename(string $id, string $name): void {}

    public function capabilities(): array
    {
        return ['start', 'stop', 'reboot', 'reinstall', 'console', 'rdns', 'power_state'];
    }
}
