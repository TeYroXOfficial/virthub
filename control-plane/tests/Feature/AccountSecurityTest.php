<?php

namespace Tests\Feature;

use App\Domain\Security\TwoFactor;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccountSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function enable2fa(User $user): string
    {
        $tf = app(TwoFactor::class);
        $secret = $tf->begin($user);
        $this->travel(-2)->minutes();
        $tf->confirm($user->fresh(), $tf->code($secret));
        $this->travelBack();

        return $secret;
    }

    public function test_kod_totp_zgodny_z_rfc6238(): void
    {
        // Wektor testowy z RFC 6238 (SHA-1, sekret "12345678901234567890").
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        $tf = app(TwoFactor::class);
        $this->assertSame('287082', $tf->code($secret, 59));
        $this->assertSame('081804', $tf->code($secret, 1111111109));
        $this->assertSame('005924', $tf->code($secret, 1234567890));
    }

    public function test_wlaczenie_2fa_wymaga_potwierdzenia_kodem(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('panel.account.security.2fa.enable'), ['current_password' => 'password'])->assertRedirect();
        $user->refresh();
        $this->assertNotNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_confirmed_at);

        $this->get(route('panel.account.security'))->assertOk()->assertSee('<svg', escape: false);

        $this->post(route('panel.account.security.2fa.confirm'), ['code' => '000000'])->assertSessionHasErrors('code', null, 'twoFactor');
        $this->assertNull($user->fresh()->two_factor_confirmed_at);

        $code = app(TwoFactor::class)->code($user->two_factor_secret);
        $this->post(route('panel.account.security.2fa.confirm'), ['code' => $code])
            ->assertSessionHas('recovery_codes', fn ($codes) => count($codes) === 8);
        $this->assertNotNull($user->fresh()->two_factor_confirmed_at);
    }

    public function test_logowanie_z_2fa_wymaga_kodu(): void
    {
        $user = User::factory()->create();
        $secret = $this->enable2fa($user);

        $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('login.two-factor'));
        $this->assertGuest();

        $this->post(route('login.two-factor'), ['code' => '123456'])->assertSessionHasErrors('code');
        $this->assertGuest();

        $this->post(route('login.two-factor'), ['code' => app(TwoFactor::class)->code($secret)])
            ->assertRedirect(route('panel.dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_ten_sam_kod_nie_dziala_drugi_raz(): void
    {
        $user = User::factory()->create();
        $secret = $this->enable2fa($user);
        $code = app(TwoFactor::class)->code($secret);

        $this->assertTrue(app(TwoFactor::class)->verify($user->fresh(), $code));
        $this->assertFalse(app(TwoFactor::class)->verify($user->fresh(), $code));
    }

    public function test_kod_zapasowy_dziala_raz(): void
    {
        $user = User::factory()->create();
        $tf = app(TwoFactor::class);
        $secret = $tf->begin($user);
        $codes = $tf->confirm($user->fresh(), $tf->code($secret));

        $this->post(route('login'), ['email' => $user->email, 'password' => 'password']);
        $this->post(route('login.two-factor'), ['code' => strtoupper($codes[0])])->assertRedirect(route('panel.dashboard'));
        $this->assertSame(7, $tf->recoveryCodesLeft($user->fresh()));

        $this->post(route('logout'));
        $this->post(route('login'), ['email' => $user->email, 'password' => 'password']);
        $this->post(route('login.two-factor'), ['code' => $codes[0]])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_wylaczenie_2fa_wymaga_hasla_i_kodu(): void
    {
        $user = User::factory()->create();
        $secret = $this->enable2fa($user);

        $this->actingAs($user->fresh())
            ->delete(route('panel.account.security.2fa.disable'), ['current_password' => 'password', 'code' => '000000'])
            ->assertSessionHasErrors('code', null, 'twoFactor');
        $this->assertNotNull($user->fresh()->two_factor_confirmed_at);

        $this->delete(route('panel.account.security.2fa.disable'), ['current_password' => 'password', 'code' => app(TwoFactor::class)->code($secret)])
            ->assertSessionHasNoErrors();
        $this->assertNull($user->fresh()->two_factor_secret);
    }

    public function test_personel_bez_2fa_nie_otworzy_administracji_gdy_wymagane(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->actingAs($admin)->get(route('panel.admin.users'))->assertOk();

        $this->put(route('panel.admin.users.policy'), ['staff_2fa' => '1'])->assertRedirect();
        $this->assertSame('1', Setting::get('security.staff_2fa'));

        $this->get(route('panel.admin.users'))->assertRedirect(route('panel.account'));
        $this->get(route('panel.dashboard'))->assertOk();

        $this->enable2fa($admin);
        $this->actingAs($admin->fresh())->get(route('panel.admin.users'))->assertOk();
    }

    public function test_dozwolone_ip_blokuja_logowanie_i_api(): void
    {
        $user = User::factory()->create();

        // Lista bez obecnego adresu odcięłaby konto — odrzucona.
        $this->actingAs($user)->put(route('panel.account.security.ips'), ['allowed_ips' => '198.51.100.0/24', 'current_password' => 'password'])
            ->assertSessionHasErrors('allowed_ips', null, 'ips');
        $this->put(route('panel.account.security.ips'), ['allowed_ips' => 'nie-ip', 'current_password' => 'password'])
            ->assertSessionHasErrors('allowed_ips', null, 'ips');
        $this->put(route('panel.account.security.ips'), ['allowed_ips' => "127.0.0.1\n198.51.100.0/24", 'current_password' => 'password'])
            ->assertSessionHasNoErrors();
        $this->assertSame("127.0.0.1\n198.51.100.0/24", $user->fresh()->allowed_ips);

        // Z innego adresu: sesja kończona, logowanie odrzucone.
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->get(route('panel.dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->post(route('login'), ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
            ->post(route('login'), ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('panel.dashboard'));

        $token = $user->createToken('t', ['servers:read'])->plainTextToken;
        auth()->forgetGuards();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->withToken($token)->getJson('/api/v1/servers')->assertForbidden();
        auth()->forgetGuards();
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.8'])->withToken($token)->getJson('/api/v1/servers')->assertOk();
    }

    public function test_tokeny_api_z_zakresami(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('panel.account.security.tokens.store'), ['name' => 'skrypt', 'scopes' => ['admin']])
            ->assertSessionHasErrors('scopes.0', null, 'tokens');
        $this->post(route('panel.account.security.tokens.store'), ['name' => 'skrypt', 'scopes' => ['servers:read'], 'expires_days' => 30])
            ->assertSessionHas('new_token');
        $plain = session('new_token');
        $this->assertSame(['servers:read'], $user->tokens()->first()->abilities);

        auth()->forgetGuards();
        $this->app['auth']->guard('web')->logout();
        $this->withToken($plain)->getJson('/api/v1/servers')->assertOk();
        auth()->forgetGuards();
        $this->withToken($plain)->postJson('/api/v1/servers', [])->assertForbidden();
        auth()->forgetGuards();
        $this->withToken($plain)->getJson('/api/v1/admin/servers')->assertForbidden();

        // Token bez zakresów serwerów (np. integracja billingu) nie czyta serwerów.
        $billing = $user->createToken('billing', ['billing'])->plainTextToken;
        auth()->forgetGuards();
        $this->withToken($billing)->getJson('/api/v1/servers')->assertForbidden();
    }

    public function test_usuniecie_tokenu_i_sesji(): void
    {
        config(['session.driver' => 'database']);
        $user = User::factory()->create();
        $token = $user->createToken('stary', ['servers:read']);
        DB::table('sessions')->insert(['id' => 'inna-sesja-123', 'user_id' => $user->id, 'ip_address' => '198.51.100.1',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/120.0 Safari/537.36', 'payload' => '', 'last_activity' => time()]);

        $this->actingAs($user)->get(route('panel.account.security'))->assertOk()
            ->assertSee('Chrome · Windows')->assertSee('stary');

        $this->delete(route('panel.account.security.sessions.destroy', hash('sha256', 'inna-sesja-123')))->assertRedirect();
        $this->assertDatabaseMissing('sessions', ['id' => 'inna-sesja-123']);

        $this->delete(route('panel.account.security.tokens.destroy', $token->accessToken->id))->assertRedirect();
        $this->assertSame(0, $user->tokens()->count());

        $other = User::factory()->create()->createToken('cudzy');
        $this->delete(route('panel.account.security.tokens.destroy', $other->accessToken->id))->assertNotFound();
    }

    public function test_administrator_resetuje_2fa_klienta(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $user = User::factory()->create(['allowed_ips' => '10.0.0.1']);
        $this->enable2fa($user);

        $this->actingAs($admin)->post(route('panel.admin.users.security', $user))->assertRedirect();
        $user->refresh();
        $this->assertNull($user->two_factor_confirmed_at);
        $this->assertNull($user->allowed_ips);
    }

    public function test_sso_nie_omija_2fa_personelu(): void
    {
        $staff = User::factory()->create(['role' => User::ROLE_SUPPORT]);
        $this->enable2fa($staff);
        Cache::put('sso:abc', ['user_id' => $staff->id, 'server_id' => null], 60);

        $this->get(route('sso.consume', 'abc'))->assertRedirect(route('login'));
        $this->assertGuest();
    }
}
