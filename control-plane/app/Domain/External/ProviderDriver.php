<?php

namespace App\Domain\External;

/**
 * Sterownik dostawcy chmury (Onidel, Hetzner…) — punkt rozszerzeń dla addonów.
 *
 * Addon rejestruje sterownik w ProviderRegistry; panel tworzy instancję z
 * danymi konta dostawcy (np. tokenem API) i woła poniższe metody. Błędy
 * dostawcy sterownik zgłasza jako ProviderException z czytelnym komunikatem.
 *
 * Wersja API: AddonManager::API_VERSION. Zmiany niezgodne wstecz podnoszą ją.
 */
interface ProviderDriver
{
    /**
     * Pola konta dostawcy w formularzu administratora.
     *
     * @return array<string, array{label:string, secret?:bool, required?:bool, help?:string}>
     */
    public static function credentialFields(): array;

    /** Sprawdza dane konta; zwraca krótki opis (np. nazwa zespołu). */
    public function test(): string;

    /** @return list<array{id:string, name:string}> */
    public function locations(): array;

    /**
     * Typy instancji. `configurable` = CPU/RAM/dysk dobierane w granicach max_*.
     *
     * @return list<array{id:string, name:string, cpu:?int, ram_mb:?int, disk_gb:?int, configurable:bool, max_cpu:?int, max_ram_mb:?int, max_disk_gb:?int, locations:list<string>}>
     */
    public function plans(): array;

    /** @return list<array{id:string, name:string, family:?string}> */
    public function images(): array;

    /** Tworzy maszynę; zwraca jej identyfikator u dostawcy. */
    public function create(CreateRequest $request): string;

    /** Stan maszyny u dostawcy. */
    public function get(string $id): RemoteServer;

    /** @param  'start'|'stop'|'reboot'|'kill'  $action */
    public function power(string $id, string $action): void;

    public function reinstall(string $id, string $image): void;

    public function destroy(string $id): void;

    /** Jednorazowy adres konsoli w przeglądarce (np. noVNC dostawcy). */
    public function console(string $id): string;

    /** Ustawia (albo usuwa, gdy null) rekord PTR dla adresu maszyny. */
    public function setRdns(string $id, string $ip, ?string $hostname): void;

    public function rename(string $id, string $name): void;

    /**
     * Co sterownik umie — panel ukrywa resztę przycisków.
     *
     * @return list<'start'|'stop'|'reboot'|'kill'|'reinstall'|'console'|'rdns'|'rename'>
     */
    public function capabilities(): array;
}
