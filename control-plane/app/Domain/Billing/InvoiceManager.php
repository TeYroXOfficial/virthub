<?php

namespace App\Domain\Billing;

use App\Domain\Mail\TemplateMailer;
use App\Models\AuditLog;
use App\Models\BillingService;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Faktury: wystawienie, zapłata (portfel, bramka, ręcznie), anulowanie i zwrot do portfela. */
class InvoiceManager
{
    public function __construct(
        private readonly Wallet $wallet,
        private readonly TemplateMailer $mailer,
    ) {}

    /**
     * @param  list<array{kind:string, description:string, amount:int, billing_service_id?:?int, period_start?:?Carbon, period_end?:?Carbon}>  $items
     */
    public function create(User $user, array $items, string $type = Invoice::TYPE_SERVICE, ?Carbon $dueAt = null, bool $notify = true): Invoice
    {
        $invoice = DB::transaction(function () use ($user, $items, $type, $dueAt) {
            $items = array_map(fn ($i) => ['amount' => Money::roundCents((int) $i['amount'])] + $i, $items);
            $sum = array_sum(array_column($items, 'amount'));
            $rate = $type === Invoice::TYPE_TOPUP ? 0 : Billing::taxRate();

            if ($rate === 0) {
                [$subtotal, $tax, $total] = [$sum, 0, $sum];
            } elseif (Billing::pricesIncludeTax()) {
                $total = $sum;
                $subtotal = Money::roundCents(Money::mulDiv($total, 10000, 10000 + $rate));
                $tax = $total - $subtotal;
            } else {
                $subtotal = $sum;
                $tax = Money::roundCents(Money::mulDiv($subtotal, $rate, 10000));
                $total = $subtotal + $tax;
            }

            $invoice = Invoice::create([
                'user_id' => $user->id,
                'number' => $this->nextNumber(),
                'type' => $type,
                'status' => Invoice::STATUS_UNPAID,
                'currency' => Billing::currency(),
                'tax_rate' => $rate,
                'subtotal' => $subtotal,
                'tax' => $tax,
                'total' => $total,
                'due_at' => $dueAt ?? now()->addDays(Billing::int('due_days')),
                'seller' => Billing::seller(),
                'buyer' => ['name' => $user->name, 'email' => $user->email],
                'notes' => Billing::get('invoice_notes') ?: null,
            ]);
            foreach ($items as $item) {
                $invoice->items()->create([
                    'billing_service_id' => $item['billing_service_id'] ?? null,
                    'kind' => $item['kind'],
                    'description' => mb_substr($item['description'], 0, 255),
                    'amount' => $item['amount'],
                    'period_start' => $item['period_start'] ?? null,
                    'period_end' => $item['period_end'] ?? null,
                ]);
            }

            return $invoice;
        });

        AuditLog::record('invoice.created', $invoice, ['number' => $invoice->number, 'total' => $invoice->total], $user);
        if ($notify && $invoice->total > 0) {
            $this->mailer->send($user, 'invoice.created', $this->vars($invoice));
        }

        return $invoice;
    }

    /** Doładowanie portfela: faktura bez VAT, po zapłacie środki trafiają do portfela. */
    public function topup(User $user, int $amount): Invoice
    {
        return $this->create($user, [[
            'kind' => 'topup',
            'description' => __('Doładowanie portfela'),
            'amount' => $amount,
        ]], Invoice::TYPE_TOPUP, now()->addDays(Billing::int('due_days')), false);
    }

    /** Zapłata z portfela — całość albo nic. */
    public function payWithWallet(Invoice $invoice, ?User $actor = null): Payment
    {
        if ($invoice->type === Invoice::TYPE_TOPUP) {
            throw new \DomainException(__('Doładowania nie można opłacić z portfela.'));
        }

        [$payment, $paidNow] = DB::transaction(function () use ($invoice, $actor) {
            $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if (! $locked->isUnpaid()) {
                throw new \DomainException(__('Ta faktura nie czeka na zapłatę.'));
            }
            $tx = $this->wallet->debit($locked->user, $locked->total, 'payment',
                __('Zapłata faktury :number', ['number' => $locked->number]), ['invoice_id' => $locked->id], false, $actor);

            return $this->record($locked, 'wallet', 'wallet-tx-'.$tx->id, $locked->total, $locked->currency, $actor, []);
        });
        if ($paidNow) {
            $this->afterPaid($invoice->refresh(), 'wallet', $payment, $actor);
        }

        return $payment;
    }

    /**
     * Zaksięgowanie płatności. Idempotentne po (bramka, identyfikator):
     * ta sama płatność zgłoszona drugi raz niczego nie zmienia. Nadpłata
     * i płatność za fakturę już opłaconą/anulowaną trafiają do portfela.
     *
     * @param  array<string, mixed>  $meta
     */
    public function markPaid(Invoice $invoice, string $gateway, ?string $reference, ?int $amount = null, ?string $currency = null, ?User $actor = null, array $meta = []): Payment
    {
        $amount ??= $invoice->total;
        $currency = strtoupper($currency ?? $invoice->currency);
        if ($currency !== $invoice->currency) {
            throw new \DomainException(__('Waluta płatności (:paid) nie zgadza się z fakturą (:invoice).', ['paid' => $currency, 'invoice' => $invoice->currency]));
        }
        if ($reference !== null && ($existing = Payment::query()->where('gateway', $gateway)->where('reference', $reference)->first())) {
            return $existing;
        }

        try {
            [$payment, $paidNow] = DB::transaction(fn () => $this->record(
                Invoice::query()->lockForUpdate()->findOrFail($invoice->id), $gateway, $reference, $amount, $currency, $actor, $meta,
            ));
        } catch (UniqueConstraintViolationException) {
            // Równoległe zgłoszenie tej samej płatności (powrót z bramki i webhook naraz).
            return Payment::query()->where('gateway', $gateway)->where('reference', $reference)->firstOrFail();
        }
        if ($paidNow) {
            $this->afterPaid($invoice->refresh(), $gateway, $payment, $actor);
        }

        return $payment;
    }

    /**
     * Część transakcyjna — faktura już zablokowana.
     *
     * @return array{0: Payment, 1: bool} płatność i czy to ona opłaciła fakturę
     */
    private function record(Invoice $invoice, string $gateway, ?string $reference, int $amount, string $currency, ?User $actor, array $meta): array
    {
        $payment = Payment::create([
            'user_id' => $invoice->user_id,
            'invoice_id' => $invoice->id,
            'gateway' => $gateway,
            'reference' => $reference,
            'amount' => $amount,
            'currency' => $currency,
            'meta' => $meta ?: null,
        ]);

        $user = $invoice->user;
        if (! $invoice->isUnpaid()) {
            // Pieniądze przyszły za fakturę, która już nie czeka — nie mogą przepaść.
            if ($gateway !== 'wallet' && $amount > 0) {
                $this->wallet->credit($user, $amount, 'refund',
                    __('Płatność za fakturę :number (już rozliczoną) — na saldo', ['number' => $invoice->number]), ['invoice_id' => $invoice->id], $actor);
            }

            return [$payment, false];
        }
        if ($amount < $invoice->total) {
            throw new \DomainException(__('Kwota płatności jest mniejsza niż kwota faktury.'));
        }

        $invoice->forceFill(['status' => Invoice::STATUS_PAID, 'paid_at' => now()])->save();
        if ($amount > $invoice->total) {
            $this->wallet->credit($user, $amount - $invoice->total, 'refund',
                __('Nadpłata faktury :number', ['number' => $invoice->number]), ['invoice_id' => $invoice->id], $actor);
        }
        if ($invoice->type === Invoice::TYPE_TOPUP && $invoice->total > 0) {
            $this->wallet->credit($user, $invoice->total, 'topup',
                __('Doładowanie — faktura :number', ['number' => $invoice->number]), ['invoice_id' => $invoice->id], $actor);
        }

        return [$payment, true];
    }

    /** Po zatwierdzeniu transakcji: uruchomienie usług (woła węzły) i e-maile. */
    private function afterPaid(Invoice $invoice, string $gateway, Payment $payment, ?User $actor): void
    {
        AuditLog::record('invoice.paid', $invoice, ['gateway' => $gateway, 'reference' => $payment->reference, 'amount' => $payment->amount], $actor);
        $services = app(ServiceManager::class);
        if ($invoice->type === Invoice::TYPE_SERVICE) {
            $services->invoicePaid($invoice, $actor);
        }
        if ($invoice->total > 0) {
            $this->mailer->send($invoice->user, 'invoice.paid', $this->vars($invoice) + ['payment' => ['method' => Payment::gatewayLabel($gateway)]]);
        }
        if ($invoice->type === Invoice::TYPE_TOPUP) {
            $user = $invoice->user->fresh();
            $this->mailer->send($user, 'wallet.topup', [
                'amount' => Money::format($invoice->total, $invoice->currency),
                'wallet' => ['balance' => Money::format($user->wallet_balance), 'url' => route('panel.billing.wallet')],
            ]);
            $services->resumeAfterTopup($user, $actor);
        }
    }

    public function cancel(Invoice $invoice, ?User $actor = null): void
    {
        DB::transaction(function () use ($invoice, $actor) {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if (! $invoice->isUnpaid()) {
                throw new \DomainException(__('Anulować można tylko nieopłaconą fakturę.'));
            }
            $invoice->forceFill(['status' => Invoice::STATUS_CANCELLED])->save();
            AuditLog::record('invoice.cancelled', $invoice, ['number' => $invoice->number], $actor);
        });
        // Zamówienie, które czekało tylko na tę fakturę, przepada.
        foreach ($invoice->items()->with('service')->get() as $item) {
            if ($item->service?->status === BillingService::STATUS_PENDING) {
                $item->service->update(['status' => BillingService::STATUS_CANCELLED]);
            }
        }
    }

    /** Zwrot opłaconej faktury na saldo portfela (zwroty na kartę — w panelu bramki). */
    public function refundToWallet(Invoice $invoice, User $actor): void
    {
        DB::transaction(function () use ($invoice, $actor) {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->status !== Invoice::STATUS_PAID || $invoice->type === Invoice::TYPE_TOPUP) {
                throw new \DomainException(__('Zwrócić można tylko opłaconą fakturę za usługi.'));
            }
            $invoice->forceFill(['status' => Invoice::STATUS_REFUNDED])->save();
            if ($invoice->total > 0) {
                $this->wallet->credit($invoice->user, $invoice->total, 'refund',
                    __('Zwrot faktury :number', ['number' => $invoice->number]), ['invoice_id' => $invoice->id], $actor);
            }
            AuditLog::record('invoice.refunded', $invoice, ['number' => $invoice->number, 'total' => $invoice->total], $actor);
        });
    }

    /** @return array<string, mixed> */
    public function vars(Invoice $invoice): array
    {
        return [
            'invoice' => [
                'number' => $invoice->number,
                'total' => Money::format($invoice->total, $invoice->currency),
                'due_at' => $invoice->due_at?->format('d.m.Y') ?? '—',
                'url' => route('panel.billing.invoice', $invoice),
            ],
            'wallet' => ['balance' => Money::format((int) $invoice->user?->fresh()?->wallet_balance), 'url' => route('panel.billing.wallet')],
        ];
    }

    /** Kolejny numer, np. FV/2026/0007 — licznik osobny dla każdego prefiksu (nowy rok = od 1). */
    private function nextNumber(): string
    {
        $prefix = strtr(Billing::get('invoice_prefix') ?: 'FV/{Y}/', ['{Y}' => now()->format('Y'), '{m}' => now()->format('m')]);
        $key = 'invoice:'.$prefix;

        return DB::transaction(function () use ($prefix, $key) {
            DB::table('billing_counters')->insertOrIgnore(['key' => $key, 'value' => 0]);
            $value = (int) DB::table('billing_counters')->where('key', $key)->lockForUpdate()->value('value') + 1;
            DB::table('billing_counters')->where('key', $key)->update(['value' => $value]);

            return $prefix.str_pad((string) $value, 4, '0', STR_PAD_LEFT);
        });
    }
}
