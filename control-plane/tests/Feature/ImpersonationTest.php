<?php

namespace Tests\Feature;

use App\Domain\Access\Impersonation;
use App\Models\AuditLog;
use App\Models\TicketDepartment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** „Zaloguj jako”: kto może, powrót na konto administratora, blokady i dziennik zdarzeń. */
class ImpersonationTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_loguje_sie_jako_klient_i_wraca(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $customer = User::factory()->create(['email' => 'klient@example.com']);

        $this->actingAs($admin)->get(route('panel.admin.users.edit', $customer))->assertOk()->assertSee(route('panel.admin.users.impersonate', $customer));
        $this->actingAs($admin)->from(route('panel.admin.users.edit', $customer))
            ->post(route('panel.admin.users.impersonate', $customer))->assertRedirect(route('panel.dashboard'));

        $this->assertAuthenticatedAs($customer);
        $this->assertSame($admin->id, session(Impersonation::SESSION_KEY));
        $this->get(route('panel.dashboard'))->assertOk()->assertSee('Jesteś zalogowany jako klient@example.com')->assertSee(route('impersonate.stop'));
        // Panel administracji jest niedostępny — to sesja klienta.
        $this->get(route('panel.admin.index'))->assertForbidden();

        // Działania w imieniu klienta mają dopisek w dzienniku.
        $this->post(route('panel.tickets.store'), ['department_id' => TicketDepartment::query()->value('id'), 'subject' => 'x', 'priority' => 'low', 'body' => 'x']);
        $this->assertSame($admin->email, AuditLog::query()->where('action', 'ticket.opened')->firstOrFail()->meta['impersonated_by']);

        // Zabezpieczeń konta klienta nie da się zmienić.
        $this->put(route('panel.account.password'), ['current_password' => 'password', 'password' => 'NoweHaslo123!', 'password_confirmation' => 'NoweHaslo123!'])
            ->assertSessionHasErrors('impersonate');

        $this->post(route('impersonate.stop'))->assertRedirect(route('panel.admin.users.edit', $customer));
        $this->assertAuthenticatedAs($admin);
        $this->assertNull(session(Impersonation::SESSION_KEY));
        $this->assertSame(1, AuditLog::query()->where('action', 'user.impersonate')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'user.impersonate_stop')->count());
    }

    public function test_wylogowanie_wraca_na_konto_administratora(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $customer = User::factory()->create();
        $this->actingAs($admin)->post(route('panel.admin.users.impersonate', $customer));

        $this->post(route('logout'))->assertRedirect();
        $this->assertAuthenticatedAs($admin);
    }

    public function test_ograniczenia(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $otherAdmin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $suspended = User::factory()->create(['suspended_at' => now()]);
        $support = User::factory()->create(['role' => User::ROLE_SUPPORT]);
        $customer = User::factory()->create();

        $this->actingAs($admin)->post(route('panel.admin.users.impersonate', $otherAdmin))->assertSessionHasErrors('impersonate');
        $this->actingAs($admin)->post(route('panel.admin.users.impersonate', $suspended))->assertSessionHasErrors('impersonate');
        $this->assertAuthenticatedAs($admin);
        // Support (nawet z dostępem do użytkowników) nie loguje się jako klient.
        $this->actingAs($support)->post(route('panel.admin.users.impersonate', $customer))->assertForbidden();
        $this->actingAs($customer)->post(route('impersonate.stop'))->assertNotFound();
    }
}
