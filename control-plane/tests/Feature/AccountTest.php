<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Moje konto: dane, avatar, hasło, reset hasła e-mailem. */
class AccountTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'email' => 'jan@example.com', 'name' => 'Jan']);
    }

    public function test_strona_konta_i_link_w_pasku(): void
    {
        $this->actingAs($this->user)->get(route('panel.dashboard'))->assertOk()->assertSee(route('panel.account'), false);
        $this->actingAs($this->user)->get(route('panel.account'))->assertOk()->assertSee('Zmiana hasła')->assertSee('jan@example.com');
    }

    public function test_zmiana_danych_i_e_maila_wymaga_hasla(): void
    {
        $this->actingAs($this->user)->put(route('panel.account.profile'), ['name' => 'Jan Nowak', 'email' => 'jan@example.com'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Jan Nowak', $this->user->fresh()->name);

        $this->actingAs($this->user)->put(route('panel.account.profile'), ['name' => 'Jan', 'email' => 'nowy@example.com'])
            ->assertSessionHasErrors('current_password');
        $this->actingAs($this->user)->put(route('panel.account.profile'), ['name' => 'Jan', 'email' => 'nowy@example.com', 'current_password' => 'password'])
            ->assertSessionHasNoErrors();
        $this->assertSame('nowy@example.com', $this->user->fresh()->email);

        User::factory()->create(['email' => 'zajety@example.com']);
        $this->actingAs($this->user)->put(route('panel.account.profile'), ['name' => 'Jan', 'email' => 'zajety@example.com', 'current_password' => 'password'])
            ->assertSessionHasErrors('email');
    }

    public function test_zmiana_hasla(): void
    {
        $this->actingAs($this->user)->put(route('panel.account.password'), [
            'current_password' => 'zle', 'password' => 'Nowe-haslo-123', 'password_confirmation' => 'Nowe-haslo-123',
        ])->assertSessionHasErrorsIn('password', 'current_password');

        $this->actingAs($this->user)->put(route('panel.account.password'), [
            'current_password' => 'password', 'password' => 'Nowe-haslo-123', 'password_confirmation' => 'Nowe-haslo-123',
        ])->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('Nowe-haslo-123', $this->user->fresh()->password));
        $this->assertAuthenticatedAs($this->user->fresh());
    }

    public function test_avatar_wgrany_serwowany_i_usuniety(): void
    {
        Storage::fake('local');
        $this->actingAs($this->user)->post(route('panel.account.avatar'), ['avatar' => UploadedFile::fake()->create('x.exe', 10)])
            ->assertSessionHasErrors('avatar');
        $this->actingAs($this->user)->post(route('panel.account.avatar'), ['avatar' => UploadedFile::fake()->image('me.png', 200, 200)])
            ->assertSessionHasNoErrors();

        $path = $this->user->fresh()->avatar_path;
        Storage::disk('local')->assertExists($path);
        $this->actingAs($this->user)->get(route('panel.account'))->assertSee($this->user->fresh()->avatarUrl(), false);
        $this->actingAs($this->user)->get(route('avatar', $this->user))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        auth()->logout();
        $this->get(route('avatar', $this->user))->assertRedirect(); // tylko po zalogowaniu (wylogowany → logowanie)

        $this->actingAs($this->user)->delete(route('panel.account.avatar.delete'));
        Storage::disk('local')->assertMissing($path);
        $this->assertNull($this->user->fresh()->avatar_path);
    }

    public function test_reset_hasla_e_mailem(): void
    {
        Notification::fake();
        $this->get(route('login'))->assertSee(route('password.request'), false);

        $this->post(route('password.email'), ['email' => 'nie-ma@example.com'])->assertSessionHas('status');
        $this->post(route('password.email'), ['email' => 'jan@example.com'])->assertSessionHas('status');
        Notification::assertNothingSentTo(User::factory()->make(['email' => 'nie-ma@example.com']));

        $token = null;
        Notification::assertSentTo($this->user, ResetPassword::class, function (ResetPassword $n) use (&$token) {
            $token = $n->token;
            $mail = $n->toMail($this->user);

            return str_contains($mail->actionUrl, '/reset-password/') && str_contains((string) $mail->subject, 'hasła');
        });

        $this->get(route('password.reset', ['token' => $token, 'email' => 'jan@example.com']))->assertOk();
        $this->post(route('password.update'), [
            'token' => 'zly', 'email' => 'jan@example.com', 'password' => 'Nowe-haslo-123', 'password_confirmation' => 'Nowe-haslo-123',
        ])->assertSessionHasErrors('email');
        $this->post(route('password.update'), [
            'token' => $token, 'email' => 'jan@example.com', 'password' => 'Nowe-haslo-123', 'password_confirmation' => 'Nowe-haslo-123',
        ])->assertRedirect(route('login'));
        $this->assertTrue(Hash::check('Nowe-haslo-123', $this->user->fresh()->password));
    }

    public function test_zawieszone_konto_nie_dostaje_linku(): void
    {
        Notification::fake();
        $this->user->forceFill(['suspended_at' => now()])->save();
        $this->post(route('password.email'), ['email' => 'jan@example.com'])->assertSessionHas('status');
        Notification::assertNothingSent();
    }
}
