<?php

namespace App\Domain\Provisioning;

use App\Models\AuditLog;
use App\Models\BillingService;
use App\Models\IpAddress;
use App\Models\IpAssignment;
use App\Models\Server;
use App\Models\ServerShare;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Udostępnianie maszyny innym kontom i zmiana jej właściciela. */
class ServerSharing
{
    /** @param  list<string>  $permissions */
    public function share(Server $server, string $email, array $permissions, ?User $actor = null): ServerShare
    {
        $user = User::query()->where('email', mb_strtolower(trim($email)))->first();
        if ($user === null) {
            throw new \DomainException(__('Nie ma konta z adresem :email — osoba musi najpierw założyć konto w panelu.', ['email' => $email]));
        }
        if ($user->id === $server->user_id) {
            throw new \DomainException(__('To właściciel maszyny.'));
        }
        if ($user->isSuspended()) {
            throw new \DomainException(__('To konto jest zawieszone.'));
        }
        $permissions = array_values(array_intersect(ServerShare::PERMISSIONS, $permissions));
        $existing = $server->shares()->where('user_id', $user->id)->first();
        if ($existing === null && $server->shares()->count() >= ServerShare::MAX_PER_SERVER) {
            throw new \DomainException(__('Maszynę można udostępnić najwyżej :count osobom.', ['count' => ServerShare::MAX_PER_SERVER]));
        }
        $share = ServerShare::updateOrCreate(['server_id' => $server->id, 'user_id' => $user->id], ['permissions' => $permissions, 'created_by' => $actor?->id]);
        AuditLog::record($existing ? 'server.share_updated' : 'server.shared', $server, ['user' => $user->email, 'permissions' => $permissions], $actor);

        return $share;
    }

    public function unshare(ServerShare $share, ?User $actor = null): void
    {
        AuditLog::record('server.unshared', $share->server, ['user' => $share->user?->email], $actor);
        $share->delete();
    }

    /**
     * Nowy właściciel maszyny. Z $withBilling usługa rozliczeniowa (kolejne
     * faktury) przechodzi razem z maszyną; bez — zmiana jest odrzucana, gdy
     * maszyna ma aktywną usługę, żeby stary właściciel nie płacił za cudzą.
     */
    public function transfer(Server $server, User $newOwner, bool $withBilling = false, ?User $actor = null): void
    {
        if ($newOwner->id === $server->user_id) {
            throw new \DomainException(__('Ta osoba już jest właścicielem maszyny.'));
        }
        $service = BillingService::query()->where('server_id', $server->id)->whereNull('terminated_at')->first();
        if ($service !== null && ! $withBilling) {
            throw new \DomainException(__('Maszyna ma aktywną usługę w billingu — zaznacz przeniesienie usługi albo najpierw ją zakończ.'));
        }
        $from = $server->user;
        DB::transaction(function () use ($server, $newOwner, $service) {
            $server->forceFill(['user_id' => $newOwner->id])->save();
            // Nowy właściciel nie potrzebuje udostępnienia samemu sobie.
            $server->shares()->where('user_id', $newOwner->id)->delete();
            $service?->forceFill(['user_id' => $newOwner->id])->save();
            // Audyt IP: od teraz adresy należą do nowego klienta.
            foreach ($server->ipAddresses()->get() as $address) {
                /** @var IpAddress $address */
                IpAssignment::track($address, $address->server_id);
            }
        });
        AuditLog::record('server.owner_changed', $server, ['from' => $from?->email, 'to' => $newOwner->email, 'billing' => $service !== null], $actor);
    }
}
