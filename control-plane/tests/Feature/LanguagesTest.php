<?php

namespace Tests\Feature;

use App\Domain\Settings\Languages;
use App\Models\Language;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/** Administracja → Języki: domyślny, włączone, własne języki i tłumaczenia z panelu. */
class LanguagesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        app(Languages::class)->apply();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/lang'));
        parent::tearDown();
    }

    private function key(string $source): string
    {
        return rtrim(strtr(base64_encode($source), '+/', '-_'), '=');
    }

    /** Ustawienia języków działają od następnego żądania (jak po restarcie aplikacji). */
    private function reboot(): void
    {
        app(Languages::class)->forget();
        app(Languages::class)->apply();
    }

    public function test_domyslny_jezyk_i_wylaczanie(): void
    {
        $this->actingAs(User::factory()->create())->get(route('panel.admin.languages'))->assertForbidden();

        $this->actingAs($this->admin)->post(route('panel.admin.languages.default'), ['code' => 'en'])->assertSessionHasNoErrors();
        $this->reboot();
        $this->post('/logout');
        // Przeglądarka (w testach: Accept-Language pl) wygrywa z domyślnym, dopóki wykrywanie jest włączone.
        $this->get(route('login'))->assertSee('<html lang="pl">', false);
        $this->actingAs($this->admin)->post(route('panel.admin.languages.default'), ['code' => 'en', 'detect_browser' => 0]);
        $this->post('/logout');
        $this->get(route('login'))->assertSee('<html lang="en">', false)->assertSee('Log in to manage your servers.');

        // Polski wyłączony: znika z przełącznika i nie da się go wybrać.
        $this->actingAs($this->admin)->put(route('panel.admin.languages.update', 'pl'), ['name' => 'Polski', 'is_enabled' => 0])->assertSessionHasNoErrors();
        $this->reboot();
        $this->post('/logout');
        $this->get(route('login'))->assertDontSee('value="pl"', false);
        $this->post(route('locale.switch'), ['locale' => 'pl'])->assertSessionHasErrors('locale');

        // Domyślnego nie da się wyłączyć.
        $this->actingAs($this->admin)->put(route('panel.admin.languages.update', 'en'), ['name' => 'English', 'is_enabled' => 0])->assertSessionHasErrors('language');
    }

    public function test_wlasny_jezyk_z_tlumaczeniami_i_fallbackiem(): void
    {
        $this->actingAs($this->admin)->post(route('panel.admin.languages.store'), ['code' => 'de', 'name' => 'Deutsch', 'base' => 'en'])
            ->assertRedirect(route('panel.admin.languages.translations', 'de'));
        $this->assertFalse(Language::query()->where('code', 'de')->sole()->is_enabled);
        $this->actingAs($this->admin)->post(route('panel.admin.languages.store'), ['code' => 'Niemiecki!', 'name' => 'x', 'base' => 'en'])->assertSessionHasErrors('code');

        // Zmienna musi zostać w tłumaczeniu.
        $this->actingAs($this->admin)->put(route('panel.admin.languages.translations.save', 'de'), [
            't' => [$this->key('Zaloguj się, aby zarządzać swoimi serwerami.') => 'Melden Sie sich an.', $this->key('Usunięto port :port.') => 'Port entfernt.'],
        ])->assertSessionHasErrors('t');

        $this->actingAs($this->admin)->put(route('panel.admin.languages.translations.save', 'de'), [
            't' => [
                $this->key('Zaloguj się, aby zarządzać swoimi serwerami.') => 'Melden Sie sich an, um Ihre Server zu verwalten.',
                $this->key('validation.required') => 'Das Feld :attribute ist erforderlich.',
                $this->key('nieznany klucz') => 'x',
            ],
        ])->assertSessionHas('status');
        $this->actingAs($this->admin)->put(route('panel.admin.languages.update', 'de'), ['name' => 'Deutsch', 'is_enabled' => 1, 'base' => 'en']);
        $this->reboot();

        $this->post('/logout');
        $this->withSession(['locale' => 'de'])->get(route('login'))->assertOk()
            ->assertSee('<html lang="de">', false)
            ->assertSee('Melden Sie sich an, um Ihre Server zu verwalten.')
            ->assertSee('Password'); // nieprzetłumaczone — z angielskiego (język bazowy), nie polskie źródło
        $this->withSession(['locale' => 'de'])->post(route('login'), [])->assertSessionHasErrors(['email' => 'Das Feld email ist erforderlich.']);

        // Eksport → import do innego języka.
        $export = $this->actingAs($this->admin)->get(route('panel.admin.languages.export', 'de'))->streamedContent();
        $data = json_decode($export, true);
        $this->assertSame('Melden Sie sich an, um Ihre Server zu verwalten.', $data['Zaloguj się, aby zarządzać swoimi serwerami.']);
        $this->assertArrayNotHasKey('nieznany klucz', $data);

        $this->actingAs($this->admin)->post(route('panel.admin.languages.store'), ['code' => 'at', 'name' => 'Österreich', 'base' => 'en']);
        $file = UploadedFile::fake()->createWithContent('de.json', $export);
        $this->actingAs($this->admin)->post(route('panel.admin.languages.import', 'at'), ['file' => $file])->assertSessionHas('status');
        $this->assertSame(2, app(Languages::class)->progress('at')['done']);

        // Usunięcie: pliki i wybór na kontach znikają.
        $user = User::factory()->create(['locale' => 'de']);
        $this->actingAs($this->admin)->delete(route('panel.admin.languages.destroy', 'de'))->assertSessionHasNoErrors();
        $this->assertNull($user->fresh()->locale);
        $this->assertFileDoesNotExist(storage_path('app/lang/de.json'));
        $this->actingAs($this->admin)->delete(route('panel.admin.languages.destroy', 'en'))->assertSessionHasErrors('language');
    }

    public function test_poprawka_wbudowanego_jezyka(): void
    {
        $this->actingAs($this->admin)->put(route('panel.admin.languages.translations.save', 'en'), [
            't' => [$this->key('Zaloguj się, aby zarządzać swoimi serwerami.') => 'Sign in to your hosting control panel.'],
        ])->assertSessionHas('status');
        $this->reboot();
        $this->post('/logout');
        $this->withSession(['locale' => 'en'])->get(route('login'))->assertSee('Sign in to your hosting control panel.');

        // Polskie teksty też można zmienić (bez dotykania kodu).
        $this->actingAs($this->admin)->put(route('panel.admin.languages.translations.save', 'pl'), [
            't' => [$this->key('Zaloguj się, aby zarządzać swoimi serwerami.') => 'Zaloguj się do panelu hostingu.'],
        ]);
        $this->reboot();
        $this->post('/logout');
        $this->withSession(['locale' => 'pl'])->get(route('login'))->assertSee('Zaloguj się do panelu hostingu.');

        // Wpisanie tekstu równego wbudowanemu usuwa nadpisanie.
        $this->actingAs($this->admin)->put(route('panel.admin.languages.translations.save', 'en'), [
            't' => [$this->key('Zaloguj się, aby zarządzać swoimi serwerami.') => 'Log in to manage your servers.'],
        ]);
        $this->assertArrayNotHasKey('Zaloguj się, aby zarządzać swoimi serwerami.', app(Languages::class)->overrides('en'));

        $this->actingAs($this->admin)->get(route('panel.admin.languages.translations', ['en', 'filter' => 'missing']))->assertOk();
        $this->actingAs($this->admin)->get(route('panel.admin.languages.translations', ['en', 'section' => 'system', 'q' => 'required']))->assertOk()->assertSee('validation.required');
        $this->actingAs($this->admin)->get(route('panel.admin.languages'))->assertOk()->assertSee('English');
    }
}
