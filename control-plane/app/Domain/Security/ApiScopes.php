<?php

namespace App\Domain\Security;

use App\Models\User;

/** Zakresy tokenów API (uprawnienia Sanctum) do wyboru przy tworzeniu tokenu. */
class ApiScopes
{
    /** @return array<string, string> zakres → opis */
    public static function for(User $user): array
    {
        $scopes = [
            'servers:read' => __('Odczyt serwerów — lista, stan, statystyki, metryki'),
            'servers:write' => __('Zarządzanie serwerami — tworzenie, zasilanie, reinstalacja, firewall, migawki, usuwanie'),
        ];
        if ($user->role !== User::ROLE_CUSTOMER) {
            $scopes['admin'] = __('Administracja — w granicach uprawnień konta');
        }
        if ($user->role === User::ROLE_ADMIN) {
            $scopes['billing'] = __('Integracja rozliczeniowa (WHMCS, Blesta…) — zakładanie i zawieszanie usług, SSO');
        }

        return $scopes;
    }

    public static function label(string $scope): string
    {
        return match ($scope) {
            '*' => __('Pełny dostęp'),
            'servers:read' => __('Odczyt serwerów'),
            'servers:write' => __('Zarządzanie serwerami'),
            'admin' => __('Administracja'),
            'billing' => __('Rozliczenia'),
            default => $scope,
        };
    }
}
