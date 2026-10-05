<?php

namespace Tests\Feature;

use App\Domain\Mail\EmailTemplates;
use App\Domain\Mail\TemplateMailer;
use App\Domain\Mail\TemplateRenderer;
use App\Domain\Provisioning\AgentResultApplier;
use App\Domain\Provisioning\ServerProvisioner;
use App\Mail\TemplatedMail;
use App\Models\EmailTemplate;
use App\Models\Hypervisor;
use App\Models\Server;
use App\Models\ServerJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Szablony e-mail: domyślne treści, nadpisania, bezpieczne zmienne i powiadomienia o zdarzeniach. */
class EmailTemplatesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Queue::fake(); // zlecenia do agenta nie wychodzą
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    public function test_kazdy_szablon_ma_obie_wersje_jezykowe_i_renderuje_sie(): void
    {
        $vars = ['brand' => 'VirtHub', 'panel_url' => 'https://p.example', 'user' => ['name' => 'Jan', 'email' => 'jan@example.com']] + EmailTemplates::sample();
        foreach (EmailTemplates::definitions() as $key => $def) {
            foreach (EmailTemplates::LOCALES as $locale) {
                $this->assertNotEmpty($def[$locale]['subject'], "{$key}/{$locale}");
                $html = TemplateRenderer::html($def[$locale]['body'], $vars);
                $this->assertStringNotContainsString('{{', $html, "niepodstawiona zmienna w {$key}/{$locale}");
                preg_match_all('/\{\{\s*([A-Za-z0-9_.]+)\s*\}\}/', $def[$locale]['subject'].$def[$locale]['body'], $m);
                foreach ($m[1] as $var) {
                    $this->assertContains($var, EmailTemplates::variables($key), "{$key}/{$locale}: zmienna {$var} nie jest opisana");
                    $this->assertNotNull(data_get($vars, $var), "{$key}/{$locale}: brak przykładowej wartości {$var}");
                }
            }
        }
    }

    public function test_zmienne_sa_escapowane(): void
    {
        $html = TemplateRenderer::html("Temat: {{ x }}\n\n[Otwórz]({{ url }}) [Zły]({{ bad }})", [
            'x' => '<img src=x onerror=alert(1)> [klik](https://evil.example) **pogrubienie**',
            'url' => 'https://panel.example.com/a?b=1',
            'bad' => 'javascript:alert(1)',
        ]);

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<strong>pogrubienie', $html);
        $this->assertStringNotContainsString('href="https://evil.example"', $html);
        $this->assertStringContainsString('href="https://panel.example.com/a?b=1"', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertSame('[#5] Temat z linią', TemplateRenderer::subject('[#{{ n }}] {{ s }}', ['n' => 5, 's' => "Temat\nz linią"]));
    }

    public function test_nadpisanie_jezyk_i_wylaczenie(): void
    {
        $user = User::factory()->create(['locale' => 'en', 'name' => 'John']);
        $mailer = app(TemplateMailer::class);

        $this->assertTrue($mailer->send($user, 'account.welcome', ['login_url' => 'https://p.example/login']));
        Mail::assertQueued(TemplatedMail::class, fn (TemplatedMail $m) => $m->subjectLine === 'Welcome to '.config('virthub.brand')
            && str_contains($m->bodyHtml, 'John') && $m->hasTo($user->email));

        EmailTemplate::create(['key' => 'account.welcome', 'locale' => 'en', 'subject' => 'Hello {{ user.name }}', 'body' => 'Custom', 'enabled' => true]);
        $mailer->send($user, 'account.welcome');
        Mail::assertQueued(TemplatedMail::class, fn (TemplatedMail $m) => $m->subjectLine === 'Hello John');

        EmailTemplate::query()->update(['enabled' => false]);
        $this->assertFalse($mailer->send($user, 'account.welcome'));
    }

    public function test_powiadomienia_o_maszynie_i_koncie(): void
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $server = Server::factory()->create(['user_id' => $customer->id, 'hypervisor_id' => Hypervisor::factory()->create()->id, 'hostname' => 'vps.example.com']);
        $job = ServerJob::create(['server_id' => $server->id, 'hypervisor_id' => $server->hypervisor_id, 'action' => 'rebuild', 'status' => 'running']);

        app(AgentResultApplier::class)->apply($job, ['status' => 'done', 'result' => []]);
        Mail::assertQueued(TemplatedMail::class, fn (TemplatedMail $m) => $m->templateKey === 'server.reinstalled' && $m->hasTo($customer->email)
            && str_contains($m->subjectLine, 'vps.example.com'));

        app(ServerProvisioner::class)->suspend($server, 'Nadużycie', $this->admin);
        Mail::assertQueued(TemplatedMail::class, fn (TemplatedMail $m) => $m->templateKey === 'server.suspended' && str_contains($m->bodyHtml, 'Nadużycie'));

        $this->actingAs($customer)->put(route('panel.account.password'), [
            'current_password' => 'password', 'password' => 'Nowe-haslo-123', 'password_confirmation' => 'Nowe-haslo-123',
        ]);
        Mail::assertQueued(TemplatedMail::class, fn (TemplatedMail $m) => $m->templateKey === 'account.password_changed' && $m->hasTo($customer->email));
    }

    public function test_edytor_szablonow(): void
    {
        $this->actingAs($this->admin)->get(route('panel.admin.emails'))->assertOk()->assertSee('server.created')->assertSee('invoice.paid');
        $this->actingAs($this->admin)->get(route('panel.admin.emails.edit', ['server.created', 'pl']))
            ->assertOk()->assertSee('{{ server.hostname }}', false)->assertSee('vps1.example.com');

        $this->actingAs($this->admin)->put(route('panel.admin.emails.update', ['server.created', 'pl']), [
            'subject' => 'Gotowe: {{ server.hostname }}', 'body' => 'Treść', 'enabled' => '1',
        ])->assertSessionHasNoErrors();
        $this->assertSame('Gotowe: {{ server.hostname }}', EmailTemplates::resolve('server.created', 'pl')['subject']);

        $this->actingAs($this->admin)->post(route('panel.admin.emails.test', ['server.created', 'pl']))->assertSessionHas('status');
        Mail::assertQueued(TemplatedMail::class, fn (TemplatedMail $m) => $m->subjectLine === 'Gotowe: vps1.example.com');

        $this->actingAs($this->admin)->delete(route('panel.admin.emails.reset', ['server.created', 'pl']));
        $this->assertFalse(EmailTemplates::resolve('server.created', 'pl')['custom']);

        $this->actingAs($this->admin)->get(route('panel.admin.emails.edit', ['nie.ma', 'pl']))->assertNotFound();
        $this->actingAs(User::factory()->create(['role' => User::ROLE_SUPPORT]))->get(route('panel.admin.emails'))->assertForbidden();
    }

    public function test_wyrenderowany_mail_ma_wersje_html_i_tekstowa(): void
    {
        $mail = new TemplatedMail('Temat', TemplateRenderer::html("Cześć **Jan**\n\n- jeden\n- dwa", []), 'x');
        $html = $mail->render();
        $this->assertStringContainsString('<strong>Jan</strong>', $html);
        $this->assertStringContainsString('- jeden', TemplateRenderer::text(TemplateRenderer::html("- jeden\n- dwa", [])));
    }
}
