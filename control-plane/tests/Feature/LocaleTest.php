<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

class LocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_domyslnie_panel_jest_po_polsku(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('<html lang="pl">', escape: false)
            ->assertSee('Zaloguj się, aby zarządzać swoimi serwerami.')
            ->assertDontSee('window.VH_T', escape: false);
    }

    public function test_jezyk_przegladarki_wybiera_angielski(): void
    {
        $this->withHeader('Accept-Language', 'en-GB,en;q=0.9')
            ->get(route('login'))
            ->assertOk()
            ->assertSee('<html lang="en">', escape: false)
            ->assertSee('Log in to manage your servers.');
    }

    public function test_nieobslugiwany_jezyk_przegladarki_zostaje_przy_domyslnym(): void
    {
        $this->withHeader('Accept-Language', 'de-DE,de;q=0.9')
            ->get(route('login'))
            ->assertSee('Zaloguj się, aby zarządzać swoimi serwerami.');
    }

    public function test_gosc_przelacza_jezyk_w_sesji(): void
    {
        $this->from(route('login'))
            ->post(route('locale.switch'), ['locale' => 'en'])
            ->assertRedirect(route('login'))
            ->assertSessionHas('locale', 'en');

        $this->get(route('login'))->assertSee('Log in to manage your servers.');
    }

    public function test_wybor_zalogowanego_uzytkownika_jest_zapamietany_na_koncie(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('panel.dashboard'))
            ->post(route('locale.switch'), ['locale' => 'en'])
            ->assertRedirect(route('panel.dashboard'));

        $this->assertSame('en', $user->fresh()->locale);

        // Nowa sesja (inne urządzenie) — język z konta ma pierwszeństwo przed przeglądarką.
        $this->flushSession();
        $this->actingAs($user->fresh())
            ->withHeader('Accept-Language', 'pl')
            ->get(route('panel.dashboard'))
            ->assertOk()
            ->assertSee('My machines')
            ->assertSee('window.VH_T', escape: false);
    }

    public function test_nieznany_jezyk_jest_odrzucany(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('locale.switch'), ['locale' => 'xx'])
            ->assertSessionHasErrors('locale');

        $this->assertNull($user->fresh()->locale);
    }

    public function test_komunikaty_walidacji_sa_w_jezyku_panelu(): void
    {
        $this->post(route('login'), [])->assertSessionHasErrors([
            'email' => 'Pole adres e-mail jest wymagane.',
        ]);

        $this->withSession(['locale' => 'en'])
            ->post(route('login'), [])
            ->assertSessionHasErrors(['email' => 'The email field is required.']);
    }

    public function test_kazdy_tekst_z_kodu_ma_tlumaczenie_angielskie(): void
    {
        $en = json_decode(file_get_contents(lang_path('en.json')), true, flags: JSON_THROW_ON_ERROR);

        $finder = (new Finder)->files()->name('*.php')
            ->in([app_path(), resource_path('views'), base_path('routes')]);

        $missing = [];
        foreach ($finder as $file) {
            preg_match_all("/(?<![\\w>])(?:__|trans_choice)\\('((?:[^'\\\\]|\\\\.)*)'/", $file->getContents(), $m);
            foreach ($m[1] as $raw) {
                $key = str_replace(["\\'", '\\\\'], ["'", '\\'], $raw);
                if (! array_key_exists($key, $en)) {
                    $missing[] = $file->getRelativePathname().': '.$key;
                }
            }
        }

        $this->assertSame([], array_values(array_unique($missing)));
    }

    public function test_tlumaczenia_zachowuja_zmienne(): void
    {
        $en = json_decode(file_get_contents(lang_path('en.json')), true, flags: JSON_THROW_ON_ERROR);

        foreach ($en as $pl => $translated) {
            $this->assertNotSame('', trim($translated), "Puste tłumaczenie: {$pl}");

            preg_match_all('/:[a-z_]+/', $pl, $a);
            preg_match_all('/:[a-z_]+/', $translated, $b);
            $this->assertEqualsCanonicalizing(array_unique($a[0]), array_unique($b[0]), "Zmienne w: {$pl}");
            $this->assertSame(substr_count($pl, '%'), substr_count($translated, '%'), "Formaty w: {$pl}");
        }
    }

    public function test_liczebniki_po_polsku_nie_biora_form_angielskich(): void
    {
        app()->setLocale('pl');
        $this->assertSame('2 porty', trans_choice(':count port|:count porty|:count portów', 2));
        $this->assertSame('5 portów', trans_choice(':count port|:count porty|:count portów', 5));

        app()->setLocale('en');
        $this->assertSame('2 ports', trans_choice(':count port|:count porty|:count portów', 2));
    }
}
