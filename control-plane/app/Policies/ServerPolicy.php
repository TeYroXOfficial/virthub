<?php

namespace App\Policies;

use App\Models\Server;
use App\Models\User;

/**
 * Własność maszyny jest granicą, której nie przekracza nikt poza personelem.
 * Sprawdzenie idzie przez politykę, a nie przez warunek w kontrolerze, żeby
 * nowy endpoint nie mógł jej przypadkiem pominąć.
 *
 * Na własnej maszynie decydują uprawnienia użytkownika (servers.*). Personel
 * na cudzej maszynie potrzebuje admin.servers, a operacji niszczących dane
 * (reinstalacja, usunięcie, przywrócenie kopii) może dokonać tylko administrator.
 */
class ServerPolicy
{
    public function viewAny(User $user): bool
    {
        return ! $user->isSuspended();
    }

    public function view(User $user, Server $server): bool
    {
        return ! $user->isSuspended()
            && ($server->user_id === $user->id || $this->staffAccess($user));
    }

    /** Operacje bez osobnego uprawnienia: podgląd hasła, statystyki na żywo. */
    public function operate(User $user, Server $server): bool
    {
        return $this->view($user, $server);
    }

    public function power(User $user, Server $server): bool
    {
        return $this->allowed($user, $server, 'servers.power');
    }

    public function console(User $user, Server $server): bool
    {
        return $this->allowed($user, $server, 'servers.console');
    }

    public function firewall(User $user, Server $server): bool
    {
        return $this->allowed($user, $server, 'servers.firewall');
    }

    public function snapshots(User $user, Server $server): bool
    {
        return $this->allowed($user, $server, 'servers.snapshots');
    }

    public function resize(User $user, Server $server): bool
    {
        return $this->allowed($user, $server, 'servers.resize');
    }

    public function rebuild(User $user, Server $server): bool
    {
        return $this->allowed($user, $server, 'servers.rebuild', destructive: true);
    }

    public function iso(User $user, Server $server): bool
    {
        return ! $server->isContainer() && $this->allowed($user, $server, 'servers.iso');
    }

    /** Przywrócenie kopii nadpisuje dysk — jak reinstalacja. */
    public function restoreSnapshot(User $user, Server $server): bool
    {
        return $this->allowed($user, $server, 'servers.snapshots', destructive: true);
    }

    /** Usunięcie maszyny razem z dyskiem. */
    public function destroy(User $user, Server $server): bool
    {
        return $this->allowed($user, $server, 'servers.delete', destructive: true);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('servers.order');
    }

    private function allowed(User $user, Server $server, string $permission, bool $destructive = false): bool
    {
        if ($user->isSuspended()) {
            return false;
        }

        if ($server->user_id === $user->id) {
            return $user->hasPermission($permission);
        }

        // Support celowo nie może usunąć ani przebudować cudzej maszyny —
        // to nieodwracalna utrata danych klienta.
        return $this->staffAccess($user) && (! $destructive || $user->isAdmin());
    }

    private function staffAccess(User $user): bool
    {
        return $user->isStaff() && $user->hasPermission('admin.servers');
    }
}
