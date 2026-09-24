<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Polecenie jest wywoływane przez instalator, więc liczy się nie tylko to, że
 * zakłada konto, ale też jak się zachowuje przy ponownym uruchomieniu i co
 * dokładnie wypisuje na stdout — instalator podstawia to do zmiennej.
 */
class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_zaklada_konto_z_podanym_haslem(): void
    {
        $this->artisan('virthub:create-admin', [
            '--email' => 'admin@example.com',
            '--password' => 'bardzo-dlugie-haslo',
        ])->assertSuccessful();

        $user = User::where('email', 'admin@example.com')->firstOrFail();
        $this->assertSame(User::ROLE_ADMIN, $user->role);
        $this->assertTrue(Hash::check('bardzo-dlugie-haslo', $user->password));
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_tryb_porcelain_wypisuje_samo_haslo(): void
    {
        // Artisan::call zamiast $this->artisan(): tylko tak dostajemy dokładnie
        // ten tekst, który trafia na stdout i który instalator przechwyci.
        $exitCode = Artisan::call('virthub:create-admin', [
            '--email' => 'admin@example.com',
            '--porcelain' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $password = trim(Artisan::output());

        // Instalator bierze to jako hasło wprost — żadnych ozdobników,
        // jedna linia, gotowa do podstawienia do zmiennej powłoki.
        $this->assertSame(1, substr_count($password, "\n") + 1);
        $this->assertGreaterThanOrEqual(12, strlen($password));

        $user = User::where('email', 'admin@example.com')->firstOrFail();
        $this->assertTrue(Hash::check($password, $user->password));
    }

    public function test_if_none_nie_rusza_istniejacego_administratora(): void
    {
        $existing = User::factory()->create([
            'email' => 'pierwszy@example.com',
            'role' => User::ROLE_ADMIN,
        ]);
        $originalPassword = $existing->password;

        $exitCode = Artisan::call('virthub:create-admin', [
            '--email' => 'drugi@example.com',
            '--if-none' => true,
            '--porcelain' => true,
        ]);

        // Ponowne uruchomienie instalatora nie może podmienić hasła działającemu
        // administratorowi ani dołożyć drugiego konta. Puste wyjście to dla
        // instalatora sygnał „konto już było".
        $this->assertSame(0, $exitCode);
        $this->assertSame('', trim(Artisan::output()));
        $this->assertSame($originalPassword, $existing->fresh()->password);
        $this->assertNull(User::where('email', 'drugi@example.com')->first());
    }

    public function test_if_none_zaklada_konto_gdy_nie_ma_administratora(): void
    {
        User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $this->artisan('virthub:create-admin', [
            '--email' => 'admin@example.com',
            '--if-none' => true,
        ])->assertSuccessful();

        $this->assertTrue(User::where('email', 'admin@example.com')->exists());
    }

    public function test_istniejacy_klient_dostaje_role_administratora(): void
    {
        User::factory()->create([
            'email' => 'klient@example.com',
            'role' => User::ROLE_CUSTOMER,
            'suspended_at' => now(),
        ]);

        $this->artisan('virthub:create-admin', [
            '--email' => 'klient@example.com',
            '--password' => 'nowe-dlugie-haslo',
        ])->assertSuccessful();

        $user = User::where('email', 'klient@example.com')->firstOrFail();
        $this->assertSame(User::ROLE_ADMIN, $user->role);
        $this->assertNull($user->suspended_at, 'Zawieszone konto administratora byłoby bezużyteczne');
        $this->assertSame(1, User::where('email', 'klient@example.com')->count());
    }

    public function test_krotkie_haslo_jest_odrzucane(): void
    {
        $this->artisan('virthub:create-admin', [
            '--email' => 'admin@example.com',
            '--password' => 'krotkie',
        ])->assertFailed();

        $this->assertFalse(User::where('email', 'admin@example.com')->exists());
    }

    public function test_niepoprawny_email_jest_odrzucany(): void
    {
        $this->artisan('virthub:create-admin', [
            '--email' => 'to-nie-jest-email',
            '--password' => 'bardzo-dlugie-haslo',
        ])->assertFailed();

        $this->assertSame(0, User::count());
    }
}
