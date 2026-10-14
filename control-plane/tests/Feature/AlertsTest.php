<?php

namespace Tests\Feature;

use App\Domain\Metrics\Alerts;
use App\Domain\Webhooks\Webhooks;
use App\Mail\TemplatedMail;
use App\Models\AlertIncident;
use App\Models\AlertRule;
use App\Models\Backup;
use App\Models\Hypervisor;
use App\Models\Server;
use App\Models\ServerMetric;
use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AlertsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Http::fake();
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function sample(Server $server, float $cpu, int $minutesAgo = 1): void
    {
        ServerMetric::create(['server_id' => $server->id, 'sampled_at' => now()->subMinutes($minutesAgo), 'cpu_percent' => $cpu,
            'ram_used_mb' => 512, 'ram_total_mb' => 1024]);
    }

    public function test_administrator_dodaje_regule(): void
    {
        $this->actingAs($this->admin)->get(route('panel.admin.alerts'))->assertOk()->assertSee(__('Maszyna: CPU (%)'));
        $this->post(route('panel.admin.alerts.store'), ['name' => 'CPU', 'metric' => 'zla', 'threshold' => 90, 'duration_minutes' => 5])
            ->assertSessionHasErrors('metric');
        $this->post(route('panel.admin.alerts.store'), ['name' => 'CPU', 'metric' => 'server_cpu', 'threshold' => 90, 'duration_minutes' => 5,
            'notify_email' => '1', 'is_active' => '1'])->assertSessionHasNoErrors();
        $rule = AlertRule::firstOrFail();
        $this->assertTrue($rule->notify_email);
        $this->assertFalse($rule->notify_webhook);

        $this->actingAs(User::factory()->create())->get(route('panel.admin.alerts'))->assertForbidden();
    }

    public function test_cpu_maszyny_otwiera_i_zamyka_alert(): void
    {
        $server = Server::factory()->create(['hostname' => 'busy.example.test']);
        $calm = Server::factory()->create();
        $rule = AlertRule::create(['name' => 'CPU > 90', 'metric' => 'server_cpu', 'threshold' => 90, 'duration_minutes' => 5]);
        Webhook::create(['name' => 'h', 'url' => 'https://hooks.test/x', 'secret' => 's', 'events' => ['alert.triggered', 'alert.resolved'], 'is_active' => true]);
        app(Webhooks::class)->forget();

        $this->sample($server, 95, 3);
        $this->sample($server, 99, 1);
        $this->sample($calm, 10);
        // Stara próbka spoza okna się nie liczy.
        $this->sample($calm, 100, 30);

        $this->assertSame(1, app(Alerts::class)->evaluate());
        $incident = AlertIncident::firstOrFail();
        $this->assertSame('busy.example.test', $incident->subject_label);
        $this->assertSame(97.0, $incident->value);
        Mail::assertQueued(TemplatedMail::class, fn ($m) => str_contains($m->subjectLine, '[ALERT] CPU > 90: busy.example.test'));
        $this->assertSame(1, WebhookDelivery::where('event', 'alert.triggered')->count());

        // Kolejny przebieg bez zmian — bez nowego powiadomienia.
        $this->assertSame(0, app(Alerts::class)->evaluate());

        $this->travel(10)->minutes();
        $this->sample($server, 20);
        $this->assertSame(1, app(Alerts::class)->evaluate());
        $this->assertNotNull($incident->fresh()->resolved_at);
        $this->assertSame(1, WebhookDelivery::where('event', 'alert.resolved')->count());
    }

    public function test_hypervisor_offline_po_czasie(): void
    {
        $node = Hypervisor::factory()->create(['enrolled_at' => now(), 'status' => Hypervisor::STATUS_OFFLINE, 'last_seen_at' => now()->subMinutes(3), 'name' => 'node-1']);
        AlertRule::create(['name' => 'Węzeł offline', 'metric' => 'hypervisor_offline', 'threshold' => 0, 'duration_minutes' => 5]);

        $this->assertSame(0, app(Alerts::class)->evaluate());
        $this->travel(3)->minutes();
        $this->assertSame(1, app(Alerts::class)->evaluate());
        $this->assertSame('node-1', AlertIncident::firstOrFail()->subject_label);

        $node->forceFill(['status' => Hypervisor::STATUS_ONLINE, 'last_seen_at' => now()])->save();
        $this->assertSame(1, app(Alerts::class)->evaluate());
        $this->assertSame(0, AlertIncident::whereNull('resolved_at')->count());
    }

    public function test_dysk_hypervisora_musi_trwac(): void
    {
        Hypervisor::factory()->create(['enrolled_at' => now(), 'last_health' => ['disk_gb_total' => 100, 'disk_gb_free' => 5]]);
        AlertRule::create(['name' => 'Dysk', 'metric' => 'hypervisor_disk', 'threshold' => 90, 'duration_minutes' => 10]);

        $this->assertSame(0, app(Alerts::class)->evaluate());
        $this->travel(5)->minutes();
        $this->assertSame(0, app(Alerts::class)->evaluate());
        $this->travel(6)->minutes();
        $this->assertSame(1, app(Alerts::class)->evaluate());
        $this->assertSame(95.0, AlertIncident::firstOrFail()->value);
    }

    public function test_nieudana_migawka(): void
    {
        $server = Server::factory()->create(['hostname' => 'b.example.test']);
        Backup::create(['server_id' => $server->id, 'name' => 'nocna', 'type' => 'snapshot', 'status' => Backup::STATUS_FAILED]);
        AlertRule::create(['name' => 'Migawki', 'metric' => 'backup_failed', 'threshold' => 0, 'duration_minutes' => 30]);

        $this->assertSame(1, app(Alerts::class)->evaluate());
        $this->assertSame('b.example.test — nocna', AlertIncident::firstOrFail()->subject_label);
        $this->travel(2)->hours();
        $this->assertSame(1, app(Alerts::class)->evaluate());
        $this->assertNotNull(AlertIncident::firstOrFail()->resolved_at);
    }

    public function test_zakres_wezla(): void
    {
        $nodeA = Hypervisor::factory()->create();
        $a = Server::factory()->create(['hypervisor_id' => $nodeA->id]);
        $b = Server::factory()->create();
        $this->sample($a, 99);
        $this->sample($b, 99);
        AlertRule::create(['name' => 'CPU A', 'metric' => 'server_cpu', 'threshold' => 90, 'duration_minutes' => 5, 'hypervisor_id' => $nodeA->id, 'notify_email' => false]);

        app(Alerts::class)->evaluate();
        $this->assertSame([$a->id], AlertIncident::pluck('subject_id')->all());
        Mail::assertNothingQueued();
    }
}
