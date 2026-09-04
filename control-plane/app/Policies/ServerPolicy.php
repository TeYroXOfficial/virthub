<?php

namespace App\Policies;

use App\Models\Server;
use App\Models\User;

/**
 * Własność maszyny jest granicą, której nie przekracza nikt poza personelem.
 * Sprawdzenie idzie przez politykę, a nie przez warunek w kontrolerze, żeby
 * nowy endpoint nie mógł jej przypadkiem pominąć.
 */
class ServerPolicy
{
    public function viewAny(User $user): bool
    {
        return ! $user->isSuspended();
    }

    public function view(User $user, Server $server): bool
    {
        return $user->isStaff() || $server->user_id === $user->id;
    }

    /** Sterowanie maszyną: zasilanie, konsola, firewall, snapshoty. */
    public function operate(User $user, Server $server): bool
    {
        if ($user->isSuspended()) {
            return false;
        }

        return $user->isStaff() || $server->user_id === $user->id;
    }

    /** Operacje niszczące dane: przebudowa, przywrócenie kopii, usunięcie. */
    public function destroy(User $user, Server $server): bool
    {
        // Support celowo nie może usunąć cudzej maszyny ani jej przebudować —
        // to nieodwracalna utrata danych klienta.
        return $user->isAdmin() || $server->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return ! $user->isSuspended();
    }
}
