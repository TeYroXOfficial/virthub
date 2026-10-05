<?php

namespace App\Policies;

use App\Models\AppServer;
use App\Models\User;

/** Aplikacją steruje właściciel; personel z działem „Aplikacje” — każdą. */
class AppServerPolicy
{
    public function view(User $user, AppServer $app): bool
    {
        return ! $user->isSuspended() && ($app->user_id === $user->id || $this->staff($user));
    }

    public function operate(User $user, AppServer $app): bool
    {
        return $this->view($user, $app);
    }

    public function create(User $user): bool
    {
        // Z włączonym billingiem klient zamawia przez sklep (płatność przed utworzeniem).
        if (\App\Domain\Billing\Billing::enabled() && ! $user->isStaff()) {
            return false;
        }

        return ! $user->isSuspended() && $user->hasPermission('apps.order');
    }

    public function destroy(User $user, AppServer $app): bool
    {
        return ! $user->isSuspended() && ($app->user_id === $user->id || ($this->staff($user) && $user->isAdmin()));
    }

    /** Zasoby, zawieszenie, usunięcie tylko z panelu — personel. */
    public function manage(User $user, AppServer $app): bool
    {
        return ! $user->isSuspended() && $this->staff($user);
    }

    private function staff(User $user): bool
    {
        return $user->isStaff() && $user->hasPermission('admin.apps');
    }
}
