<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_niezalogowany_uzytkownik_trafia_na_logowanie(): void
    {
        $this->get('/')->assertRedirect(route('panel.dashboard'));
        $this->get(route('panel.dashboard'))->assertRedirect(route('login'));
    }

    public function test_strona_logowania_sie_renderuje(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Zaloguj się', escape: false);
    }

    public function test_panel_renderuje_sie_dla_zalogowanego(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('panel.dashboard'))
            ->assertOk()
            ->assertSee('Moje maszyny');
    }

    public function test_endpoint_zdrowia_odpowiada(): void
    {
        $this->get('/up')->assertOk();
    }
}
