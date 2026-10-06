<?php

namespace App\Policies;

use App\Models\ExternalServer;
use App\Models\User;

/** Te same zasady co dla zwykłego VPS (ServerPolicy): właściciel z uprawnieniem albo personel z admin.servers. */
class ExternalServerPolicy
{
    public function view(User $user, ExternalServer $server): bool
    {
        return ! $user->isSuspended() && ($server->user_id === $user->id || $this->staff($user));
    }

    public function operate(User $user, ExternalServer $server): bool
    {
        return $this->view($user, $server);
    }

    public function power(User $user, ExternalServer $server): bool
    {
        return $this->allowed($user, $server, 'servers.power');
    }

    public function console(User $user, ExternalServer $server): bool
    {
        return $this->allowed($user, $server, 'servers.console');
    }

    public function rebuild(User $user, ExternalServer $server): bool
    {
        return $this->allowed($user, $server, 'servers.rebuild', destructive: true);
    }

    private function allowed(User $user, ExternalServer $server, string $permission, bool $destructive = false): bool
    {
        if ($user->isSuspended()) {
            return false;
        }
        if ($server->user_id === $user->id) {
            return $user->hasPermission($permission);
        }

        return $this->staff($user) && (! $destructive || $user->isAdmin());
    }

    private function staff(User $user): bool
    {
        return $user->isStaff() && $user->hasPermission('admin.servers');
    }
}
