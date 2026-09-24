<?php

namespace App\Enums;

/**
 * Rodzaj maszyny, jaki daje węzeł.
 *
 * Węzeł jest albo jednym, albo drugim — zależy to od sprzętu (VT-x/AMD-V),
 * nie od wyboru administratora. Szablon systemu też jest jednego rodzaju:
 * obraz dysku qcow2 dla KVM, obraz systemu plików dla kontenera.
 */
enum Virtualization: string
{
    case Kvm = 'kvm';
    case Lxc = 'lxc';

    public function label(): string
    {
        return match ($this) {
            self::Kvm => 'Maszyna wirtualna (KVM)',
            self::Lxc => 'Kontener (LXC)',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::Kvm => 'KVM',
            self::Lxc => 'LXC',
        };
    }

    /**
     * Opis dla klienta przy zamawianiu — różnica ma znaczenie praktyczne,
     * nie tylko techniczne.
     */
    public function description(): string
    {
        return match ($this) {
            self::Kvm => 'Pełna izolacja i własne jądro systemu. Możesz ładować moduły jądra, '
                .'uruchamiać Dockera i dowolny system.',
            self::Lxc => 'Lżejszy i szybszy start, współdzielone jądro hosta. Bez własnych modułów '
                .'jądra; Docker w kontenerze nie jest wspierany.',
        };
    }
}
