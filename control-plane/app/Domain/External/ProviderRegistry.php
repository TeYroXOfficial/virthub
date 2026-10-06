<?php

namespace App\Domain\External;

use App\Models\ProviderAccount;

/**
 * Sterowniki dostawców zarejestrowane przez addony (w ich ServiceProviderze):
 *
 *     app(ProviderRegistry::class)->register('onidel', 'Onidel', OnidelDriver::class);
 */
class ProviderRegistry
{
    /** @var array<string, array{name:string, class:class-string<ProviderDriver>, addon:?string}> */
    private array $drivers = [];

    /** @param  class-string<ProviderDriver>  $class */
    public function register(string $key, string $name, string $class, ?string $addon = null): void
    {
        if (! is_subclass_of($class, ProviderDriver::class)) {
            throw new \InvalidArgumentException("{$class} nie implementuje ProviderDriver");
        }
        $this->drivers[$key] = ['name' => $name, 'class' => $class, 'addon' => $addon];
    }

    public function has(string $key): bool
    {
        return isset($this->drivers[$key]);
    }

    /** @return array<string, array{name:string, class:class-string<ProviderDriver>, addon:?string}> */
    public function all(): array
    {
        return $this->drivers;
    }

    public function name(string $key): string
    {
        return $this->drivers[$key]['name'] ?? $key;
    }

    /** @return array<string, array{label:string, secret?:bool, required?:bool, help?:string}> */
    public function fields(string $key): array
    {
        return isset($this->drivers[$key]) ? $this->drivers[$key]['class']::credentialFields() : [];
    }

    /** Sterownik dla konta dostawcy. Brak sterownika (addon wyłączony / bez licencji) → wyjątek. */
    public function for(ProviderAccount $account): ProviderDriver
    {
        $driver = $this->drivers[$account->driver] ?? throw new ProviderException(
            __('Sterownik :driver jest niedostępny — sprawdź licencję i addony.', ['driver' => $account->driver])
        );

        return app()->makeWith($driver['class'], ['credentials' => $account->credentials()]);
    }
}
