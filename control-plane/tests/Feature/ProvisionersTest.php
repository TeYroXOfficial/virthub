<?php

namespace Tests\Feature;

use App\Domain\Agent\ServerPayload;
use App\Models\IpAddress;
use App\Models\OsTemplate;
use App\Models\Provisioner;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProvisionersTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_tworzy_provisioner_i_przypina_szablony(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $linux = OsTemplate::factory()->create();
        $windows = OsTemplate::factory()->create(['name' => 'Windows Server', 'family' => 'windows', 'image_file' => 'win.qcow2']);

        $this->actingAs($admin)->get(route('panel.admin.provisioners.create'))->assertOk()
            ->assertSee('Ubuntu 24.04 LTS')->assertDontSee('Windows Server');

        $base = ['name' => 'docker', 'path' => '/var/lib/virthub/provision', 'is_active' => '1'];
        $this->post(route('panel.admin.provisioners.store'), array_merge($base, ['content' => 'echo bez shebang']))->assertSessionHasErrors('content');
        $this->post(route('panel.admin.provisioners.store'), array_merge($base, ['content' => "#!/bin/sh\n", 'path' => '/tmp/../etc']))->assertSessionHasErrors('path');
        $this->post(route('panel.admin.provisioners.store'), array_merge($base, ['content' => "#!/bin/sh\n", 'templates' => [$windows->id]]))->assertSessionHasErrors('templates.0');
        $this->post(route('panel.admin.provisioners.store'), array_merge($base, ['content' => "#!/bin/bash\r\necho {{ server.hostname }}\r\n", 'templates' => [$linux->id]]))
            ->assertSessionHasNoErrors();

        $provisioner = Provisioner::firstOrFail();
        $this->assertSame("#!/bin/bash\necho {{ server.hostname }}\n", $provisioner->content);
        $this->assertSame([$linux->id], $provisioner->templates()->pluck('os_templates.id')->all());

        $this->actingAs(User::factory()->create())->get(route('panel.admin.provisioners'))->assertForbidden();
    }

    public function test_ladunek_dla_agenta_ma_skrypty_ze_zmiennymi(): void
    {
        $template = OsTemplate::factory()->create();
        $second = Provisioner::create(['name' => 'second', 'content' => "#!/bin/sh\necho 2\n"]);
        $first = Provisioner::create(['name' => 'first', 'content' => "#!/bin/sh\necho {{ server.hostname }} {{server.ipv4}} {{ user.email }}\n", 'path' => '/root/setup']);
        Provisioner::create(['name' => 'off', 'content' => "#!/bin/sh\n", 'is_active' => false])->templates()->attach($template->id);
        $template->provisioners()->attach([$second->id => ['sort_order' => 5], $first->id => ['sort_order' => 1]]);

        $user = User::factory()->create(['email' => 'klient@example.test']);
        $server = Server::factory()->create(['os_template_id' => $template->id, 'user_id' => $user->id, 'hostname' => 'vm1.example.test']);
        IpAddress::factory()->create(['server_id' => $server->id, 'address' => '203.0.113.77', 'is_primary' => true]);

        $payload = ServerPayload::forCreate($server->fresh());
        $this->assertSame(['first', 'second'], array_column($payload['scripts'], 'name'));
        $this->assertSame("#!/bin/sh\necho vm1.example.test 203.0.113.77 klient@example.test\n", $payload['scripts'][0]['content']);
        $this->assertSame('/root/setup', $payload['scripts'][0]['path']);
    }

    public function test_bez_provisionerow_i_na_windows_nie_ma_klucza_scripts(): void
    {
        $server = Server::factory()->create();
        $this->assertArrayNotHasKey('scripts', ServerPayload::forCreate($server));

        $windows = OsTemplate::factory()->create(['family' => 'windows', 'image_file' => 'win.qcow2']);
        Provisioner::create(['name' => 'w', 'content' => "#!/bin/sh\n"])->templates()->attach($windows->id);
        $this->assertArrayNotHasKey('scripts', ServerPayload::forCreate(Server::factory()->create(['os_template_id' => $windows->id])));
    }
}
