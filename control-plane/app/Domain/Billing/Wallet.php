<?php

namespace App\Domain\Billing;

use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;

/**
 * Portfel klienta. Każda zmiana salda idzie przez tę klasę: pod blokadą
 * wiersza użytkownika, z wpisem w księdze i saldem po operacji.
 */
class Wallet
{
    /** @param  array{invoice_id?:?int, billing_service_id?:?int}  $links */
    public function credit(User $user, int $amount, string $type, string $description, array $links = [], ?User $actor = null): WalletTransaction
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException(__('Kwota uznania musi być dodatnia.'));
        }

        return $this->apply($user, $amount, $type, $description, $links, $actor, true);
    }

    /**
     * Obciążenie portfela. Bez $allowNegative saldo nie może spaść poniżej
     * zera (InsufficientFunds); opłaty godzinowe mogą — wtedy usługi są zawieszane.
     *
     * @param  array{invoice_id?:?int, billing_service_id?:?int}  $links
     */
    public function debit(User $user, int $amount, string $type, string $description, array $links = [], bool $allowNegative = false, ?User $actor = null): WalletTransaction
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException(__('Kwota obciążenia musi być dodatnia.'));
        }

        return $this->apply($user, -$amount, $type, $description, $links, $actor, $allowNegative);
    }

    /** Korekta administratora — dowolny znak, saldo może zejść poniżej zera tylko przy odjęciu. */
    public function adjust(User $user, int $amount, string $description, User $actor): WalletTransaction
    {
        if ($amount === 0) {
            throw new \InvalidArgumentException(__('Korekta nie może być zerowa.'));
        }

        return $this->apply($user, $amount, 'adjustment', $description, [], $actor, true);
    }

    public function balance(User $user): int
    {
        return (int) User::query()->whereKey($user->id)->value('wallet_balance');
    }

    private function apply(User $user, int $delta, string $type, string $description, array $links, ?User $actor, bool $allowNegative): WalletTransaction
    {
        return DB::transaction(function () use ($user, $delta, $type, $description, $links, $actor, $allowNegative) {
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $after = (int) $locked->wallet_balance + $delta;
            if ($delta < 0 && $after < 0 && ! $allowNegative) {
                throw new InsufficientFunds(__('Za mało środków w portfelu (saldo: :balance).', ['balance' => Money::format((int) $locked->wallet_balance)]));
            }

            $locked->forceFill(['wallet_balance' => $after])->save();
            $user->setAttribute('wallet_balance', $after);
            if ($after > 0 && $locked->wallet_notified_at !== null && $delta > 0) {
                // Po doładowaniu ostrzeżenie o niskim saldzie może przyjść znowu.
                $locked->forceFill(['wallet_notified_at' => null])->save();
            }

            return WalletTransaction::create([
                'user_id' => $user->id,
                'type' => $type,
                'amount' => $delta,
                'balance_after' => $after,
                'description' => mb_substr($description, 0, 255),
                'invoice_id' => $links['invoice_id'] ?? null,
                'billing_service_id' => $links['billing_service_id'] ?? null,
                'actor_id' => $actor?->id,
            ]);
        });
    }
}
