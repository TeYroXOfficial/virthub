<?php

namespace Tests\Feature;

use App\Domain\Settings\MailSettings;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Administracja → Poczta (SMTP): ustawienia w bazie zamiast .env. */
class MailSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function form(array $overrides = []): array
    {
        return $overrides + [
            'enabled' => '1', 'host' => 'smtp.example.com', 'port' => '587', 'encryption' => 'tls',
            'username' => 'panel@example.com', 'password' => 'tajne-haslo', 'from_address' => 'panel@example.com', 'from_name' => 'VirtHub',
        ];
    }

    public function test_strona_i_ostrzezenie_na_przegladzie(): void
    {
        $this->actingAs($this->admin)->get(route('panel.admin.index'))->assertOk()->assertSee(route('panel.admin.mail'), false);
        $this->actingAs($this->admin)->get(route('panel.admin.mail'))->assertOk()->assertSee('Serwer SMTP');

        $support = User::factory()->create(['role' => User::ROLE_SUPPORT]);
        $this->actingAs($support)->get(route('panel.admin.mail'))->assertForbidden();
        $this->actingAs(User::factory()->create())->put(route('panel.admin.mail.update'), $this->form())->assertForbidden();
    }

    public function test_zapis_nadpisuje_konfiguracje_a_haslo_jest_zaszyfrowane(): void
    {
        $this->actingAs($this->admin)->put(route('panel.admin.mail.update'), $this->form())->assertSessionHasNoErrors();

        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('smtp.example.com', config('mail.mailers.smtp.host'));
        $this->assertSame(587, config('mail.mailers.smtp.port'));
        $this->assertSame('smtp', config('mail.mailers.smtp.scheme'));
        $this->assertSame('tajne-haslo', config('mail.mailers.smtp.password'));
        $this->assertSame('panel@example.com', config('mail.from.address'));
        $this->assertNotSame('tajne-haslo', Setting::query()->find('mail.password')->value);
        $this->assertTrue(MailSettings::configured());

        // Puste hasło = bez zmian; SSL → smtps. Strona nie pokazuje hasła.
        $this->actingAs($this->admin)->put(route('panel.admin.mail.update'), $this->form(['password' => '', 'encryption' => 'ssl', 'port' => '465']));
        $this->assertSame('tajne-haslo', config('mail.mailers.smtp.password'));
        $this->assertSame('smtps', config('mail.mailers.smtp.scheme'));
        $this->actingAs($this->admin)->get(route('panel.admin.mail'))->assertDontSee('tajne-haslo');

        // Po „restarcie” (nowe żądanie, świeża konfiguracja) ustawienia wracają z bazy.
        config(['mail.default' => 'array', 'mail.mailers.smtp.host' => '127.0.0.1']);
        MailSettings::apply();
        $this->assertSame('smtp.example.com', config('mail.mailers.smtp.host'));
    }

    public function test_walidacja_i_wylaczenie(): void
    {
        $this->actingAs($this->admin)->put(route('panel.admin.mail.update'), $this->form(['host' => '']))->assertSessionHasErrors('host');

        $this->actingAs($this->admin)->put(route('panel.admin.mail.update'), $this->form(['enabled' => null, 'host' => '']))->assertSessionHasNoErrors();
        $this->assertSame('log', config('mail.default'));
        $this->assertFalse(MailSettings::configured());
    }

    public function test_testowy_email(): void
    {
        $this->actingAs($this->admin)->post(route('panel.admin.mail.test'), ['to' => 'ja@example.com'])->assertSessionHas('status');
        $sent = Mail::mailer('array')->getSymfonyTransport()->messages();
        $this->assertCount(1, $sent);
        $this->assertSame('ja@example.com', $sent->first()->getOriginalMessage()->getTo()[0]->getAddress());

        $this->actingAs($this->admin)->post(route('panel.admin.mail.test'), ['to' => 'zly'])->assertSessionHasErrors('to');
    }

    public function test_blad_serwera_pokazany_przy_tescie(): void
    {
        $this->actingAs($this->admin)->put(route('panel.admin.mail.update'), $this->form(['host' => '127.0.0.1', 'port' => '1']));
        $this->actingAs($this->admin)->post(route('panel.admin.mail.test'), ['to' => 'ja@example.com'])
            ->assertSessionHasErrors('to');
    }
}
