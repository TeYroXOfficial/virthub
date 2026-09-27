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
            self::Kvm => __('Maszyna wirtualna (KVM)'),
            self::Lxc => __('Kontener (LXC)'),
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
            self::Kvm => __('Pełna izolacja i własne jądro systemu. Możesz ładować moduły jądra, ')
                .__('uruchamiać Dockera i dowolny system.'),
            self::Lxc => __('Lżejszy i szybszy start, współdzielone jądro hosta. Bez własnych modułów ')
                .__('jądra; Docker w kontenerze nie jest wspierany.'),
        };
    }
}
