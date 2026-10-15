<?php

namespace Tests\Feature;

use App\Domain\Provisioning\AgentResultApplier;
use App\Domain\Provisioning\ServerHardware;
use App\Mail\TemplatedMail;
use App\Models\Hypervisor;
use App\Models\IpAddress;
use App\Models\IpPool;
use App\Models\OsTemplate;
use App\Models\Server;
use App\Models\ServerJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Windows: oświecenia Hyper-V (węzeł → szablon → maszyna) i profil Direct RDP. */
class WindowsProfileTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Hypervisor $node;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->node = Hypervisor::factory()->create(['virtualization' => 'kvm']);
    }

    private function windowsServer(array $template = [], array $server = []): Server
    {
        $tpl = OsTemplate::factory()->manualInstall()->create($template);
        $s = Server::factory()->create(['hypervisor_id' => $this->node->id, 'os_template_id' => $tpl->id, 'agent_uuid' => (string) Str::uuid()] + $server);
        $pool = IpPool::factory()->create(['hypervisor_id' => $this->node->id]);
        IpAddress::factory()->create(['ip_pool_id' => $pool->id, 'hypervisor_id' => $this->node->id, 'server_id' => $s->id, 'address' => '203.0.113.'.(10 + Server::count())]);

        return $s->fresh();
    }

    public function test_dziedziczenie_hyperv(): void
    {
        $linux = Server::factory()->create(['hypervisor_id' => $this->node->id]);
        $this->assertSame('off', ServerHardware::resolve($linux)['hyperv']['value']);

        $win = $this->windowsServer();
        $this->assertSame(['value' => 'standard', 'source' => 'default'], ServerHardware::resolve($win)['hyperv']);
        $this->node->forceFill(['hw_hyperv' => 'full'])->save();
        $this->assertSame(['value' => 'full', 'source' => 'hypervisor'], ServerHardware::resolve($win->fresh())['hyperv']);
        $win->template->forceFill(['hw_hyperv' => 'passthrough'])->save();
        $this->assertSame('template', ServerHardware::resolve($win->fresh())['hyperv']['source']);

        $this->actingAs($this->admin)->put(route('panel.servers.hardware', $win), ['hw_hyperv' => 'off'])->assertSessionHasNoErrors();
        $this->assertSame('off', $win->fresh()->hw_hyperv);
        $this->assertSame('off', ServerHardware::payload($win->fresh())['hardware']['hyperv']);
        $this->put(route('panel.servers.hardware', $win), ['hw_hyperv' => 'turbo'])->assertSessionHasErrors('hw_hyperv');
        $this->get(route('panel.servers.show', $win))->assertOk()->assertSee('Oświecenia Hyper-V');

        // Klient nie zmienia oświeceń.
        $owner = $win->user;
        $this->actingAs($owner)->put(route('panel.servers.hardware', $win), ['hw_hyperv' => 'full']);
        $this->assertSame('off', $win->fresh()->hw_hyperv);
    }

    public function test_direct_rdp_mail_i_plik(): void
    {
        Mail::fake();
        $win = $this->windowsServer(['direct_rdp' => true], ['root_password' => 'Tajne-Haslo-123']);
        $job = ServerJob::create(['server_id' => $win->id, 'hypervisor_id' => $this->node->id, 'action' => 'create', 'status' => ServerJob::STATUS_RUNNING, 'payload' => []]);
        app(AgentResultApplier::class)->apply($job, ['status' => 'done', 'result' => ['uuid' => $win->agent_uuid, 'state' => 'running']]);
        Mail::assertQueued(TemplatedMail::class, fn ($m) => $m->templateKey === 'server.rdp_ready'
            && str_contains($m->bodyHtml, '203.0.113.11:3389') && str_contains($m->bodyHtml, 'Tajne-Haslo-123') && str_contains($m->bodyHtml, 'Administrator'));

        $response = $this->actingAs($win->user)->get(route('panel.servers.rdp', $win))->assertOk();
        $this->assertStringContainsString('full address:s:203.0.113.11:3389', $response->getContent());
        $this->assertStringNotContainsString('Tajne', $response->getContent());
        $this->assertStringContainsString('.rdp', $response->headers->get('Content-Disposition'));
        $this->actingAs(User::factory()->create())->get(route('panel.servers.rdp', $win))->assertForbidden();

        // Bez profilu: zwykły mail bez hasła; Linux nie ma pliku RDP.
        $plain = $this->windowsServer();
        $job = ServerJob::create(['server_id' => $plain->id, 'hypervisor_id' => $this->node->id, 'action' => 'create', 'status' => ServerJob::STATUS_RUNNING, 'payload' => []]);
        app(AgentResultApplier::class)->apply($job, ['status' => 'done', 'result' => ['uuid' => $plain->agent_uuid, 'state' => 'running']]);
        Mail::assertQueued(TemplatedMail::class, fn ($m) => $m->templateKey === 'server.created');
        $linux = Server::factory()->create(['hypervisor_id' => $this->node->id]);
        $this->actingAs($this->admin)->get(route('panel.servers.rdp', $linux))->assertNotFound();
    }

    public function test_formularze_szablonu_i_wezla(): void
    {
        $tpl = OsTemplate::factory()->manualInstall()->create();
        $this->actingAs($this->admin)->put(route('panel.admin.templates.update', $tpl), [
            'name' => $tpl->name, 'version' => '2022', 'min_disk_gb' => 40, 'hw_hyperv' => 'full', 'direct_rdp' => 1,
        ])->assertSessionHasNoErrors();
        $this->assertSame('full', $tpl->fresh()->hw_hyperv);
        $this->assertTrue($tpl->fresh()->direct_rdp);
    }
}
