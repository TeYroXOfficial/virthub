<?php

namespace Tests\Feature;

use App\Domain\Mail\Mailouts;
use App\Mail\TemplatedMail;
use App\Models\Hypervisor;
use App\Models\Mailout;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class MailoutsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'email' => 'admin@example.test']);
    }

    private function draft(array $audience = ['type' => 'customers'], bool $respectOptOut = true): Mailout
    {
        return Mailout::create(['subject' => 'Cześć {{ user.name }}', 'body' => "Masz **{{ servers.count }}** maszyn.\n\n[Panel]({{ panel_url }})",
            'audience' => $audience, 'respect_opt_out' => $respectOptOut, 'created_by' => $this->admin->id]);
    }

    public function test_administrator_tworzy_szkic_z_podgladem(): void
    {
        $this->actingAs($this->admin)->get(route('panel.admin.mailouts.create'))->assertOk()->assertSee('user.name');

        $this->post(route('panel.admin.mailouts.store'), ['subject' => 'Prace', 'body' => 'Treść', 'audience' => 'hypervisors'])
            ->assertSessionHasErrors('ids');
        $this->post(route('panel.admin.mailouts.store'), ['subject' => 'Prace {{ user.name }}', 'body' => 'Hej **{{ user.email }}**', 'audience' => 'customers'])
            ->assertRedirect();

        $mailout = Mailout::firstOrFail();
        $this->assertSame(['type' => 'customers', 'ids' => []], $mailout->audience);
        $this->get(route('panel.admin.mailouts.show', $mailout))->assertOk()
            ->assertSee('<strong>admin@example.test</strong>', escape: false);

        $this->actingAs(User::factory()->create())->get(route('panel.admin.mailouts'))->assertForbidden();
    }

    public function test_grupy_odbiorcow(): void
    {
        $node = Hypervisor::factory()->create();
        $other = Hypervisor::factory()->create();
        $onNode = User::factory()->create();
        Server::factory()->create(['user_id' => $onNode->id, 'hypervisor_id' => $node->id]);
        $onOther = User::factory()->create();
        Server::factory()->create(['user_id' => $onOther->id, 'hypervisor_id' => $other->id]);
        User::factory()->create(); // bez maszyn
        User::factory()->create(['suspended_at' => now()]);
        $optedOut = User::factory()->create(['mailout_opt_out' => true]);
        Server::factory()->create(['user_id' => $optedOut->id, 'hypervisor_id' => $node->id]);

        $m = app(Mailouts::class);
        $this->assertSame(3, $m->query(['type' => 'customers'], true)->count());
        $this->assertSame(4, $m->query(['type' => 'customers'], false)->count());
        $this->assertSame(2, $m->query(['type' => 'with_servers'], true)->count());
        $this->assertSame([$onNode->id], $m->query(['type' => 'hypervisors', 'ids' => [$node->id]], true)->pluck('id')->all());
        $this->assertSame(2, $m->query(['type' => 'hypervisors', 'ids' => [$node->id]], false)->count());
        $this->assertSame([$this->admin->id], $m->query(['type' => 'staff'], true)->pluck('id')->all());
    }

    public function test_wysylka_partiami_z_wlasnymi_zmiennymi(): void
    {
        $users = User::factory()->count(3)->create();
        Server::factory()->count(2)->create(['user_id' => $users[0]->id]);
        $mailout = $this->draft();

        $this->actingAs($this->admin)->post(route('panel.admin.mailouts.start', $mailout))->assertRedirect();
        $mailout->refresh();
        $this->assertSame(Mailout::SENT, $mailout->status);
        $this->assertSame(3, $mailout->total);
        $this->assertSame(3, $mailout->sent_count);

        Mail::assertSent(TemplatedMail::class, 3);
        Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail) => $mail->hasTo($users[0]->email)
            && $mail->subjectLine === 'Cześć '.$users[0]->name
            && str_contains($mail->bodyHtml, '<strong>2</strong>')
            && str_contains($mail->bodyHtml, 'mailouts/unsubscribe'));

        // Drugi start tego samego — nie.
        $this->post(route('panel.admin.mailouts.start', $mailout))->assertStatus(409);
    }

    public function test_limit_partii_i_kontynuacja(): void
    {
        User::factory()->count(Mailouts::BATCH + 5)->create();
        $mailout = $this->draft();

        app(Mailouts::class)->start($mailout, $this->admin);
        $mailout->refresh();
        $this->assertSame(Mailout::SENDING, $mailout->status);
        $this->assertSame(Mailouts::BATCH, $mailout->sent_count);

        app(Mailouts::class)->process();
        $this->assertSame(Mailout::SENT, $mailout->fresh()->status);
        $this->assertSame(Mailouts::BATCH + 5, $mailout->fresh()->sent_count);
    }

    public function test_zatrzymanie_wysylki(): void
    {
        User::factory()->count(Mailouts::BATCH + 5)->create();
        $mailout = $this->draft();
        app(Mailouts::class)->start($mailout, $this->admin);

        $this->actingAs($this->admin)->post(route('panel.admin.mailouts.cancel', $mailout))->assertRedirect();
        $this->assertSame(Mailout::CANCELLED, $mailout->fresh()->status);
        $this->assertSame(0, app(Mailouts::class)->process());
        $this->assertSame(5, $mailout->recipients()->where('status', 'skipped')->count());
    }

    public function test_wypisanie_z_linku(): void
    {
        $user = User::factory()->create();
        $url = URL::signedRoute('mailouts.unsubscribe', ['user' => $user->id]);

        $this->get(route('mailouts.unsubscribe', ['user' => $user->id]))->assertForbidden();
        $this->get($url)->assertOk()->assertSee($user->email);
        $this->assertFalse($user->fresh()->mailout_opt_out);
        $this->post($url)->assertOk();
        $this->assertTrue($user->fresh()->mailout_opt_out);

        // Ogłoszenie serwisowe dociera mimo wypisania; zwykłe — nie.
        $this->assertSame(0, app(Mailouts::class)->count($this->draft()));
        $this->assertSame(1, app(Mailouts::class)->count($this->draft(['type' => 'customers'], false)));
    }

    public function test_przelacznik_w_koncie(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->put(route('panel.account.mailouts'), [])->assertRedirect();
        $this->assertTrue($user->fresh()->mailout_opt_out);
        $this->put(route('panel.account.mailouts'), ['mailouts' => '1']);
        $this->assertFalse($user->fresh()->mailout_opt_out);
    }

    public function test_test_do_siebie(): void
    {
        $mailout = $this->draft();
        $this->actingAs($this->admin)->post(route('panel.admin.mailouts.test', $mailout))->assertSessionHas('status');
        Mail::assertSent(TemplatedMail::class, fn ($m) => $m->hasTo('admin@example.test') && str_starts_with($m->subjectLine, '[TEST]'));
        $this->assertSame(Mailout::DRAFT, $mailout->fresh()->status);
    }
}
