<?php

namespace App\Domain\Billing;

use App\Models\BillingService;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Support\Carbon;

/** Liczby i serie dzienne billingu — pulpit administracji i Billing → Przegląd. */
class BillingStats
{
    /** Bramki, przez które wpływają prawdziwe pieniądze (portfel i „za darmo” to przesunięcia wewnętrzne). */
    public const INCOME_GATEWAYS = ['stripe', 'paypal', 'manual'];

    /** @return array<string, int> */
    public function summary(): array
    {
        $paid = Invoice::query()->where('status', Invoice::STATUS_PAID)->where('type', Invoice::TYPE_SERVICE);

        return [
            'month' => (int) (clone $paid)->where('paid_at', '>=', now()->startOfMonth())->sum('total'),
            'income_month' => (int) Payment::query()->whereIn('gateway', self::INCOME_GATEWAYS)->where('created_at', '>=', now()->startOfMonth())->sum('amount'),
            'unpaid' => (int) Invoice::query()->where('status', Invoice::STATUS_UNPAID)->sum('total'),
            'overdue' => Invoice::query()->where('status', Invoice::STATUS_UNPAID)->where('due_at', '<', now())->count(),
            'active' => BillingService::query()->where('status', BillingService::STATUS_ACTIVE)->count(),
            'suspended' => BillingService::query()->where('status', BillingService::STATUS_SUSPENDED)->count(),
            'wallets' => (int) User::query()->sum('wallet_balance'),
            'metered' => -(int) WalletTransaction::query()->where('type', 'usage')->where('created_at', '>=', now()->startOfMonth())->sum('amount'),
        ];
    }

    /**
     * Serie dzienne z ostatnich N dni (z dniami bez ruchu = 0).
     *
     * @return array{income: list<array{date:Carbon, value:int}>, services: list<array{date:Carbon, value:int}>}
     */
    public function daily(int $days = 30): array
    {
        $from = now()->startOfDay()->subDays($days - 1);
        $income = Payment::query()->whereIn('gateway', self::INCOME_GATEWAYS)->where('created_at', '>=', $from)
            ->selectRaw('DATE(created_at) as d, SUM(amount) as v')->groupBy('d')->pluck('v', 'd');
        $services = BillingService::query()->where('created_at', '>=', $from)->where('status', '!=', BillingService::STATUS_CANCELLED)
            ->selectRaw('DATE(created_at) as d, COUNT(*) as v')->groupBy('d')->pluck('v', 'd');

        $fill = function ($source) use ($from, $days) {
            $out = [];
            for ($i = 0; $i < $days; $i++) {
                $date = $from->copy()->addDays($i);
                $out[] = ['date' => $date, 'value' => (int) ($source[$date->toDateString()] ?? 0)];
            }

            return $out;
        };

        return ['income' => $fill($income), 'services' => $fill($services)];
    }

    /** Aktywne usługi według produktu (najwięcej na górze). @return list<array{label:string, value:int}> */
    public function byProduct(int $limit = 6): array
    {
        $rows = BillingService::query()->whereIn('status', BillingService::LIVE)->with('product:id,name')
            ->selectRaw('product_id, COUNT(*) as c')->groupBy('product_id')->orderByDesc('c')->get();
        $top = $rows->take($limit)->map(fn ($r) => ['label' => $r->product?->name ?? __('usunięty produkt'), 'value' => (int) $r->c])->values()->all();
        $rest = (int) $rows->slice($limit)->sum('c');
        if ($rest > 0) {
            $top[] = ['label' => __('Pozostałe'), 'value' => $rest];
        }

        return $top;
    }
}
