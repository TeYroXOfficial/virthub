<?php

namespace Tests\Feature;

use App\Domain\Tickets\TicketService;
use App\Mail\TemplatedMail;
use App\Models\Server;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketCannedResponse;
use App\Models\TicketDepartment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Zgłoszenia: klient, personel, uprawnienia, załączniki, powiadomienia i automatyczne zamykanie. */
class TicketsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $customer;

    private TicketDepartment $department;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('local');
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->customer = User::factory()->create();
        $this->department = TicketDepartment::query()->ordered()->firstOrFail();
    }

    private function openTicket(array $overrides = []): Ticket
    {
        $this->actingAs($this->customer)->post(route('panel.tickets.store'), $overrides + [
            'department_id' => $this->department->id,
            'subject' => 'Nie działa SSH',
            'priority' => 'high',
            'body' => "Od rana nie mogę się połączyć.\n<script>alert(1)</script>",
        ])->assertRedirect();

        return Ticket::query()->latest('id')->firstOrFail();
    }

    public function test_klient_otwiera_zgloszenie_a_personel_dostaje_powiadomienie(): void
    {
        $ticket = $this->openTicket();

        $this->assertSame(Ticket::STATUS_OPEN, $ticket->status);
        $this->assertSame('high', $ticket->priority);
        $this->assertCount(1, $ticket->messages);
        Mail::assertQueued(TemplatedMail::class, fn (TemplatedMail $m) => $m->templateKey === 'ticket.opened' && $m->hasTo($this->customer->email));
        Mail::assertQueued(TemplatedMail::class, fn (TemplatedMail $m) => $m->templateKey === 'ticket.staff_new' && $m->hasTo($this->admin->email));

        // Treść wiadomości jest escapowana w wątku.
        $this->actingAs($this->customer)->get(route('panel.tickets.show', $ticket))
            ->assertOk()->assertSee('Nie działa SSH')->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_klient_nie_widzi_cudzych_zgloszen_ani_panelu_personelu(): void
    {
        $ticket = $this->openTicket();
        $other = User::factory()->create();

        $this->actingAs($other)->get(route('panel.tickets.show', $ticket))->assertNotFound();
        $this->actingAs($other)->post(route('panel.tickets.reply', $ticket), ['body' => 'x'])->assertNotFound();
        $this->actingAs($this->customer)->get(route('panel.admin.tickets'))->assertForbidden();
        $this->actingAs($this->customer)->get(route('panel.admin.tickets.show', $ticket))->assertForbidden();
    }

    public function test_usluga_innego_klienta_nie_jest_przypinana(): void
    {
        $foreign = Server::factory()->create(['user_id' => User::factory()->create()->id]);
        $ticket = $this->openTicket(['service' => 'server:'.$foreign->id]);
        $this->assertNull($ticket->service_type);

        $own = Server::factory()->create(['user_id' => $this->customer->id]);
        $ticket = $this->openTicket(['service' => 'server:'.$own->id]);
        $this->assertSame('server', $ticket->service_type);
        $this->assertTrue($ticket->service()->is($own));
    }

    public function test_odpowiedz_personelu_notatka_wewnetrzna_i_odpowiedz_klienta(): void
    {
        $ticket = $this->openTicket();
        Mail::fake();

        // Notatka — bez maila do klienta, bez zmiany stanu, niewidoczna dla klienta.
        $this->actingAs($this->admin)->post(route('panel.admin.tickets.reply', $ticket), ['body' => 'Sprawdzić firewall węzła', 'internal' => 1])->assertRedirect();
        $this->assertSame(Ticket::STATUS_OPEN, $ticket->fresh()->status);
        Mail::assertNothingQueued();
        $this->actingAs($this->customer)->get(route('panel.tickets.show', $ticket))->assertDontSee('Sprawdzić firewall węzła');

        // Odpowiedź — klient dostaje maila, stan „odpowiedziano”, przypisanie do odpowiadającego.
        $this->actingAs($this->admin)->post(route('panel.admin.tickets.reply', $ticket), ['body' => 'Proszę zrestartować sshd'])->assertRedirect();
        $ticket->refresh();
        $this->assertSame(Ticket::STATUS_ANSWERED, $ticket->status);
        $this->assertSame($this->admin->id, $ticket->assigned_to);
        $this->assertSame('staff', $ticket->last_reply_by);
        Mail::assertQueued(TemplatedMail::class, fn (TemplatedMail $m) => $m->templateKey === 'ticket.replied' && $m->hasTo($this->customer->email));

        // Odpowiedź klienta → przypisany pracownik dostaje maila.
        $this->actingAs($this->customer)->post(route('panel.tickets.reply', $ticket), ['body' => 'Dalej nie działa'])->assertRedirect();
        $this->assertSame(Ticket::STATUS_CUSTOMER_REPLY, $ticket->fresh()->status);
        Mail::assertQueued(TemplatedMail::class, fn (TemplatedMail $m) => $m->templateKey === 'ticket.staff_reply' && $m->hasTo($this->admin->email));

        $this->actingAs($this->admin)->get(route('panel.admin.tickets.show', $ticket))
            ->assertOk()->assertSee('Sprawdzić firewall węzła')->assertSee('notatka wewnętrzna');
    }

    public function test_zamkniecie_i_ponowne_otwarcie(): void
    {
        $ticket = $this->openTicket();

        $this->actingAs($this->customer)->post(route('panel.tickets.close', $ticket))->assertRedirect();
        $this->assertTrue($ticket->fresh()->isClosed());
        Mail::assertNotQueued(TemplatedMail::class, fn (TemplatedMail $m) => $m->templateKey === 'ticket.closed');

        // Odpowiedź klienta otwiera ponownie.
        $this->actingAs($this->customer)->post(route('panel.tickets.reply', $ticket), ['body' => 'Wróciło'])->assertRedirect();
        $this->assertSame(Ticket::STATUS_CUSTOMER_REPLY, $ticket->fresh()->status);
        $this->assertNull($ticket->fresh()->closed_at);

        // Zamknięcie przez personel → mail do klienta.
        $this->actingAs($this->admin)->put(route('panel.admin.tickets.update', $ticket), ['status' => 'closed', 'priority' => 'low'])->assertRedirect();
        $this->assertTrue($ticket->fresh()->isClosed());
        Mail::assertQueued(TemplatedMail::class, fn (TemplatedMail $m) => $m->templateKey === 'ticket.closed' && $m->hasTo($this->customer->email));

        $this->actingAs($this->customer)->post(route('panel.tickets.reopen', $ticket))->assertRedirect();
        $this->assertSame(Ticket::STATUS_CUSTOMER_REPLY, $ticket->fresh()->status);
    }

    public function test_zalaczniki_tylko_dla_uprawnionych(): void
    {
        $ticket = $this->openTicket(['attachments' => [UploadedFile::fake()->image('zrzut.png', 50, 50)]]);
        $file = TicketAttachment::query()->firstOrFail();
        Storage::disk('local')->assertExists($file->path);
        $this->assertStringStartsWith('tickets/'.$ticket->id.'/', $file->path);
        $this->assertSame('zrzut.png', $file->original_name);

        $this->actingAs($this->customer)->get(route('panel.tickets.attachment', $file))
            ->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs($this->admin)->get(route('panel.tickets.attachment', $file))->assertOk();
        $this->actingAs(User::factory()->create())->get(route('panel.tickets.attachment', $file))->assertNotFound();
    }

    public function test_niedozwolony_typ_zalacznika_jest_odrzucany(): void
    {
        $this->actingAs($this->customer)->post(route('panel.tickets.store'), [
            'department_id' => $this->department->id, 'subject' => 'x', 'priority' => 'low', 'body' => 'x',
            'attachments' => [UploadedFile::fake()->create('evil.html', 1, 'text/html')],
        ])->assertSessionHasErrors('attachments.0');
        $this->assertSame(0, Ticket::query()->count());
    }

    public function test_support_z_uprawnieniem_obsluguje_kolejke(): void
    {
        $support = User::factory()->create(['role' => User::ROLE_SUPPORT]);
        $ticket = $this->openTicket();

        $this->actingAs($support)->get(route('panel.admin.tickets'))->assertOk()->assertSee('Nie działa SSH');
        $this->actingAs($support)->get(route('panel.admin.tickets', ['status' => 'closed']))->assertOk()->assertDontSee('Nie działa SSH');
        $this->actingAs($support)->get(route('panel.admin.tickets', ['q' => '#'.$ticket->id]))->assertOk()->assertSee('Nie działa SSH');
        $this->actingAs($support)->put(route('panel.admin.tickets.update', $ticket), [
            'status' => 'on_hold', 'priority' => 'urgent', 'assigned_to' => $support->id,
        ])->assertRedirect();
        $this->assertSame('urgent', $ticket->fresh()->priority);
        $this->assertSame($support->id, $ticket->fresh()->assigned_to);
        // Usuwanie zgłoszeń tylko dla administratora.
        $this->actingAs($support)->delete(route('panel.admin.tickets.destroy', $ticket))->assertForbidden();

        $noPerm = User::factory()->create(['role' => User::ROLE_SUPPORT, 'permissions' => ['admin.servers']]);
        $this->actingAs($noPerm)->get(route('panel.admin.tickets'))->assertForbidden();
    }

    public function test_dzialy_gotowe_odpowiedzi_i_ustawienia(): void
    {
        $this->actingAs($this->admin)->post(route('panel.admin.tickets.departments.store'), ['name' => 'Abuse', 'is_active' => 1])->assertRedirect();
        $dept = TicketDepartment::query()->where('name', 'Abuse')->firstOrFail();
        $this->actingAs($this->admin)->put(route('panel.admin.tickets.departments.update', $dept), ['name' => 'Abuse', 'sort_order' => 5])->assertRedirect();
        $this->assertFalse($dept->fresh()->is_active);
        // Nieaktywny dział nie przyjmuje zgłoszeń.
        $this->actingAs($this->customer)->post(route('panel.tickets.store'), ['department_id' => $dept->id, 'subject' => 'x', 'priority' => 'low', 'body' => 'x'])
            ->assertSessionHasErrors('department_id');

        $this->actingAs($this->admin)->post(route('panel.admin.tickets.canned.store'), ['title' => 'Restart', 'body' => 'Proszę zrestartować maszynę.'])->assertRedirect();
        $this->assertSame(1, TicketCannedResponse::query()->count());

        $ticket = $this->openTicket();
        $this->actingAs($this->admin)->get(route('panel.admin.tickets.show', $ticket))->assertOk()->assertSee('Proszę zrestartować maszynę.');
        $this->actingAs($this->admin)->delete(route('panel.admin.tickets.departments.destroy', $this->department))->assertSessionHasErrors('department');

        $this->actingAs($this->admin)->put(route('panel.admin.tickets.settings.update'), ['autoclose_days' => 3])->assertRedirect();
        $this->assertSame(3, (int) Setting::get('tickets.autoclose_days'));
        $this->actingAs($this->admin)->get(route('panel.admin.tickets.settings'))->assertOk()->assertSee('Abuse');
    }

    public function test_automatyczne_zamykanie_po_braku_odpowiedzi_klienta(): void
    {
        Setting::put(['tickets.autoclose_days' => 2]);
        $old = $this->openTicket();
        $old->update(['status' => Ticket::STATUS_ANSWERED, 'last_reply_at' => now()->subDays(3)]);
        $fresh = $this->openTicket();
        $fresh->update(['status' => Ticket::STATUS_ANSWERED, 'last_reply_at' => now()->subDay()]);
        $waiting = $this->openTicket();
        $waiting->update(['last_reply_at' => now()->subDays(10)]); // czeka na nas — nie zamykamy

        $this->assertSame(1, app(TicketService::class)->autoClose());
        $this->assertTrue($old->fresh()->isClosed());
        $this->assertFalse($fresh->fresh()->isClosed());
        $this->assertFalse($waiting->fresh()->isClosed());
    }

    public function test_listy_klienta_i_menu(): void
    {
        $this->openTicket();
        $this->actingAs($this->customer)->get(route('panel.tickets.index'))->assertOk()->assertSee('Nie działa SSH')->assertSee(route('panel.tickets.create'));
        $this->actingAs($this->customer)->get(route('panel.tickets.index', ['status' => 'closed']))->assertOk()->assertDontSee('Nie działa SSH');
        $this->actingAs($this->customer)->get(route('panel.tickets.create'))->assertOk()->assertSee($this->department->name);
        $this->actingAs($this->admin)->get(route('panel.admin.index'))->assertOk()->assertSee(route('panel.admin.tickets'));
    }
}
