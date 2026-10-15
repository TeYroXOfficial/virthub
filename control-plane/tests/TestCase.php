<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Klient testowy Symfony wysyła domyślnie „Accept-Language: en-us”,
        // a panel dobiera język do przeglądarki — testy zakładają polski.
        $this->withHeader('Accept-Language', 'pl');
        // Testy działają tak, jakby użytkownik przed chwilą potwierdził tożsamość
        // (step-up); testy samego step-up czyszczą to ustawienie.
        $this->withSession(['auth.confirmed_at' => time() + 10 * 365 * 86400]);
    }
}
