<?php

namespace App\Domain\Billing;

use App\Domain\Apps\AppProvisioner;
use App\Domain\Mail\TemplateMailer;
use App\Domain\Provisioning\ServerProvisioner;
use App\Models\AppEgg;
use App\Models\AppServer;
use App\Models\AuditLog;
use App\Models\BillingService;
use App\Models\HypervisorGroup;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\OsTemplate;
use App\Models\Product;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cykl życia usługi rozliczanej: zamówienie, uruchomienie po zapłacie,
 * odnowienia, opłaty godzinowe, zawieszenie i usunięcie za brak płatności.
 */
class ServiceManager
{
    /** Ile zaległych okresów naliczyć w jednym przebiegu (po długiej przerwie harmonogramu). */
    private const MAX_CATCH_UP = 48;

    public function __construct(
        private readonly Wallet $wallet,
        private readonly InvoiceManager $invoices,
        private readonly TemplateMailer $mailer,
    ) {}

    // --- zamówienie -------------------------------------------------------------------

    /**
     * Złożenie zamówienia. Cykle okresowe: faktura na pierwszy okres (+ opłata
     * instalacyjna), opłacona od razu z portfela, jeśli klient tak wybrał i ma
     * środki. Godzinowe/dzienne: pierwsza opłata z portfela i od razu start.
     *
     * @param  array<string, mixed>  $config  dane z formularza (system, nazwa, lokalizacja…)
     * @return array{0: BillingService, 1: ?Invoice}
     *
     * @throws InsufficientFunds|\DomainException
     */
    public function checkout(User $user, Product $product, string $cycle, array $config, bool $payFromWallet): array
    {
        $product = $product->fresh(['prices', 'package', 'plan']) ?? throw new \DomainException(__('Produkt nie istnieje.'));
        $price = $product->priceFor($cycle);
        if ($price === null || ! $product->is_active || ! $product->deliverable()) {
            throw new \DomainException(__('Ten produkt nie jest dostępny w wybranym cyklu.'));
        }

        $renews = $product->renews($cycle);
        $service = DB::transaction(function () use ($user, $product, $cycle, $config, $price, $renews) {
            // Blokada produktu — dwa równoległe zamówienia nie przekroczą limitu sztuk.
            $locked = Product::query()->lockForUpdate()->findOrFail($product->id);
            if (($left = $locked->remaining()) !== null && $left < 1) {
                throw new \DomainException(__('Produkt :name jest wyprzedany.', ['name' => $product->name]));
            }
            // Limit na klienta — ważny zwłaszcza dla produktów za darmo.
            if ($locked->per_user_limit !== null) {
                $owned = BillingService::query()->where('user_id', $user->id)->where('product_id', $locked->id)
                    ->whereIn('status', [BillingService::STATUS_PENDING, ...BillingService::LIVE])->count();
                if ($owned >= $locked->per_user_limit) {
                    throw new \DomainException(trans_choice('Możesz mieć najwyżej :count usługę :name.|Możesz mieć najwyżej :count usługi :name.|Możesz mieć najwyżej :count usług :name.', $locked->per_user_limit, ['name' => $product->name]));
                }
            }

            return BillingService::create([
                'user_id' => $user->id,
                'product_id' => $product->id,
                'name' => mb_substr($product->name.' — '.($config['hostname'] ?? $config['name'] ?? ''), 0, 150),
                'cycle' => $cycle,
                'amount' => $price,
                'renews' => $renews,
                // Odnawiane godziny/dni — z portfela co okres; jednorazowe zawsze z góry w całości.
                'metered' => $renews && Cycle::metered($cycle),
                'status' => BillingService::STATUS_PENDING,
                'config' => $config,
            ]);
        });
        AuditLog::record('billing.ordered', $service, ['product' => $product->name, 'cycle' => $cycle], $user);

        if ($service->metered()) {
            return [$this->startMetered($user, $service, $product), null];
        }

        $start = now();
        $items = [];
        if ($product->setup_fee > 0) {
            $items[] = ['kind' => 'setup', 'description' => __('Opłata instalacyjna: :name', ['name' => $service->name]), 'amount' => $product->setup_fee, 'billing_service_id' => $service->id];
        }
        $end = Cycle::add($start, $cycle);
        $items[] = [
            'kind' => 'initial',
            'description' => $this->periodDescription($service, $start, $end),
            'amount' => $price,
            'billing_service_id' => $service->id,
            'period_start' => $start,
            'period_end' => $end,
        ];

        $total = $this->previewTotal($items);
        $free = $total === 0;
        $canPay = ! $free && $payFromWallet && $this->wallet->balance($user) >= $total;
        $invoice = $this->invoices->create($user, $items, Invoice::TYPE_SERVICE, null, ! $canPay && ! $free);
        if ($free) {
            // Darmowy okres: faktura na 0 zł rozliczona od razu, usługa startuje.
            $this->invoices->markPaid($invoice, 'free', 'free-'.$invoice->id, 0, null, $user);
        } elseif ($canPay) {
            try {
                $this->invoices->payWithWallet($invoice, $user);
            } catch (InsufficientFunds) {
                // Saldo zmieniło się w międzyczasie — faktura czeka na zapłatę.
                $this->mailer->send($user, 'invoice.created', $this->invoices->vars($invoice));
            }
        }

        return [$service->refresh(), $invoice->refresh()];
    }

    /** Kwota brutto, jaką klient zapłaci za pozycje (podgląd przed wystawieniem faktury). */
    public function previewTotal(array $items): int
    {
        $sum = array_sum(array_map(fn ($i) => Money::roundCents((int) $i['amount']), $items));

        return Billing::pricesIncludeTax() || Billing::taxRate() === 0
            ? $sum
            : $sum + Money::roundCents(Money::mulDiv($sum, Billing::taxRate(), 10000));
    }

    private function startMetered(User $user, BillingService $service, Product $product): BillingService
    {
        $first = Billing::gross($service->amount);
        $setup = $product->setup_fee > 0 ? Billing::gross($product->setup_fee) : 0;
        // Darmowa usługa godzinowa nie wymaga salda.
        $required = $first + $setup > 0 ? max(Billing::money('min_balance_metered'), $first + $setup) : 0;

        try {
            DB::transaction(function () use ($user, $service, $first, $setup, $required) {
                $balance = (int) User::query()->whereKey($user->id)->lockForUpdate()->value('wallet_balance');
                if ($balance < $required) {
                    throw new InsufficientFunds(__('Usługa rozliczana godzinowo wymaga co najmniej :amount w portfelu. Doładuj portfel i spróbuj ponownie.', ['amount' => Money::format($required)]));
                }
                if ($setup > 0) {
                    $this->wallet->debit($user, $setup, 'usage', __('Opłata instalacyjna: :name', ['name' => $service->name]), ['billing_service_id' => $service->id]);
                }
                if ($first > 0) {
                    $this->wallet->debit($user, $first, 'usage', $this->usageDescription($service, now()), ['billing_service_id' => $service->id]);
                }
            });
        } catch (InsufficientFunds $e) {
            $service->update(['status' => BillingService::STATUS_CANCELLED, 'last_error' => $e->getMessage()]);
            throw $e;
        }

        $this->activate($service, $first + $setup);

        return $service->refresh();
    }

    // --- uruchomienie i płatności -------------------------------------------------------

    /**
     * Tworzy maszynę/aplikację opłaconej usługi. Gdy się nie uda (brak miejsca,
     * szablon wyłączony), zapłacona kwota wraca do portfela, a usługa jest anulowana.
     */
    public function activate(BillingService $service, int $refundOnFailure = 0, ?User $actor = null): bool
    {
        if ($service->status !== BillingService::STATUS_PENDING) {
            return false;
        }
        $product = $service->product;
        $config = $service->config ?? [];

        try {
            if ($product === null) {
                throw new \DomainException(__('Produkt został usunięty.'));
            }
            $location = isset($config['location_id']) ? HypervisorGroup::query()->find($config['location_id']) : null;

            if ($product->type === Product::TYPE_VPS) {
                $package = $product->package ?? throw new \DomainException(__('Pakiet produktu nie istnieje.'));
                $template = OsTemplate::query()->where('is_active', true)->find($config['template_id'] ?? 0)
                    ?? throw new \DomainException(__('Wybrany system nie jest już dostępny.'));
                $server = app(ServerProvisioner::class)->order(
                    user: $service->user,
                    package: $package,
                    template: $template,
                    hostname: (string) $config['hostname'],
                    sshKeys: $config['ssh_keys'] ?? [],
                    label: $config['label'] ?? null,
                    billingReference: 'billing:'.$service->id,
                    location: $location,
                );
                $links = ['server_id' => $server->id];
            } else {
                $plan = $product->plan ?? throw new \DomainException(__('Plan produktu nie istnieje.'));
                $egg = AppEgg::query()->find($config['egg_id'] ?? 0) ?? throw new \DomainException(__('Wybrany szablon aplikacji nie jest już dostępny.'));
                $app = app(AppProvisioner::class)->order($service->user, $egg, $plan, (string) $config['name'], $config['image'] ?? null, [], null, $location);
                $links = ['app_server_id' => $app->id];
            }
        } catch (Throwable $e) {
            Log::warning('Billing: nie udało się uruchomić usługi', ['service' => $service->id, 'error' => $e->getMessage()]);
            $service->update(['status' => BillingService::STATUS_CANCELLED, 'last_error' => mb_substr($e->getMessage(), 0, 500)]);
            if ($refundOnFailure > 0) {
                $this->wallet->credit($service->user, $refundOnFailure, 'refund',
                    __('Zwrot — nie udało się uruchomić usługi :name', ['name' => $service->name]), ['billing_service_id' => $service->id], $actor);
            }
            AuditLog::record('billing.activation_failed', $service, ['error' => $e->getMessage(), 'refund' => $refundOnFailure], $actor);

            return false;
        }

        $service->update($links + [
            'status' => BillingService::STATUS_ACTIVE,
            'next_due_at' => Cycle::add(now(), $service->cycle),
            // Okres jednorazowy: po jego końcu usługa jest usuwana, bez faktury odnowienia.
            'cancel_at_period_end' => ! $service->renews,
            'last_error' => null,
        ]);
        AuditLog::record('billing.activated', $service, $links, $actor);

        return true;
    }

    /** Opłacona faktura: nowe usługi startują, odnawiane dostają kolejny okres. */
    public function invoicePaid(Invoice $invoice, ?User $actor = null): void
    {
        $items = $invoice->items()->whereNotNull('billing_service_id')->get();
        $itemsSum = max(1, (int) $invoice->items()->sum('amount'));

        foreach ($items->groupBy('billing_service_id') as $serviceId => $serviceItems) {
            $service = BillingService::query()->find($serviceId);
            if ($service === null) {
                continue;
            }
            if ($service->status === BillingService::STATUS_PENDING) {
                $share = Money::mulDiv($invoice->total, (int) $serviceItems->sum('amount'), $itemsSum);
                $this->activate($service, $share, $actor);

                continue;
            }

            $renewal = $serviceItems->where('kind', 'renewal')->sortByDesc('period_end')->first();
            if ($renewal?->period_end && $service->isLive()) {
                if ($service->next_due_at === null || $renewal->period_end->greaterThan($service->next_due_at)) {
                    $service->update(['next_due_at' => $renewal->period_end]);
                }
                if ($service->status === BillingService::STATUS_SUSPENDED && $service->suspend_reason === 'unpaid'
                    && ! $this->hasOverdueInvoice($service)) {
                    $this->unsuspend($service, $actor);
                }
            }
        }
    }

    /** Po doładowaniu: opłaca zaległe faktury z portfela i wznawia usługi godzinowe. */
    public function resumeAfterTopup(User $user, ?User $actor = null): void
    {
        if (Billing::get('auto_pay') === '1') {
            $unpaid = Invoice::query()->where('user_id', $user->id)->where('type', Invoice::TYPE_SERVICE)
                ->where('status', Invoice::STATUS_UNPAID)->orderBy('due_at')->get();
            foreach ($unpaid as $invoice) {
                if ($this->wallet->balance($user) < $invoice->total) {
                    break;
                }
                try {
                    $this->invoices->payWithWallet($invoice, $actor);
                } catch (\DomainException) {
                    // Opłacona w międzyczasie albo brak środków — pomijamy.
                }
            }
        }

        if ($this->wallet->balance($user) > 0) {
            BillingService::query()->where('user_id', $user->id)->where('status', BillingService::STATUS_SUSPENDED)
                ->where('suspend_reason', 'unpaid')->where('metered', true)->get()
                ->each(fn (BillingService $s) => $this->unsuspend($s, $actor));
        }
    }

    // --- zawieszenie, wznowienie, usunięcie ------------------------------------------------

    public function suspend(BillingService $service, string $reason, ?User $actor = null): void
    {
        if ($service->status !== BillingService::STATUS_ACTIVE) {
            return;
        }
        $service->update(['status' => BillingService::STATUS_SUSPENDED, 'suspend_reason' => $reason, 'suspended_at' => now()]);
        $text = $reason === 'unpaid' ? __('Brak płatności') : __('Zawieszona przez administratora');

        try {
            if ($service->server && ! $service->server->isSuspended()) {
                app(ServerProvisioner::class)->suspend($service->server, $text, $actor, $reason !== 'unpaid');
            } elseif ($service->appServer && ! $service->appServer->isSuspended()) {
                app(AppProvisioner::class)->suspend($service->appServer, $text, $actor);
            }
        } catch (Throwable $e) {
            $service->update(['last_error' => mb_substr($e->getMessage(), 0, 500)]);
        }
        AuditLog::record('billing.suspended', $service, ['reason' => $reason], $actor);

        if ($reason === 'unpaid' && $service->user) {
            $this->mailer->send($service->user, 'service.suspended_unpaid', [
                'service' => ['name' => $service->name],
                'terminate_at' => now()->addDays(Billing::int('terminate_days'))->format('d.m.Y'),
                'billing' => ['url' => route('panel.billing')],
            ]);
        }
    }

    public function unsuspend(BillingService $service, ?User $actor = null): void
    {
        if ($service->status !== BillingService::STATUS_SUSPENDED) {
            return;
        }
        $service->update([
            'status' => BillingService::STATUS_ACTIVE,
            'suspend_reason' => null,
            'suspended_at' => null,
            // Godzinowe naliczamy od chwili wznowienia — czas zawieszenia jest darmowy.
            'next_due_at' => $service->metered() ? now() : $service->next_due_at,
        ]);
        try {
            if ($service->server?->isSuspended()) {
                app(ServerProvisioner::class)->unsuspend($service->server, $actor);
            } elseif ($service->appServer?->isSuspended()) {
                app(AppProvisioner::class)->unsuspend($service->appServer, $actor);
            }
        } catch (Throwable $e) {
            $service->update(['last_error' => mb_substr($e->getMessage(), 0, 500)]);
        }
        AuditLog::record('billing.unsuspended', $service, [], $actor);
    }

    /**
     * Usuwa maszynę/aplikację i kończy usługę. Gdy węzeł nie odpowiada,
     * usługa zostaje jak jest (z błędem) i harmonogram spróbuje ponownie.
     */
    public function terminate(BillingService $service, string $reason, ?User $actor = null): bool
    {
        if (! in_array($service->status, [BillingService::STATUS_ACTIVE, BillingService::STATUS_SUSPENDED, BillingService::STATUS_PENDING], true)) {
            return false;
        }

        try {
            $server = $service->server;
            if ($server !== null && ! $server->trashed() && $server->state?->value !== 'deleting') {
                app(ServerProvisioner::class)->destroy($server, $actor);
            }
            $app = $service->appServer;
            if ($app !== null) {
                app(AppProvisioner::class)->destroy($app, $actor, $app->hypervisor === null);
            }
        } catch (Throwable $e) {
            $service->update(['last_error' => mb_substr($e->getMessage(), 0, 500)]);
            Log::warning('Billing: nie udało się usunąć usługi', ['service' => $service->id, 'error' => $e->getMessage()]);

            return false;
        }

        $wasPending = $service->status === BillingService::STATUS_PENDING;
        $service->update([
            'status' => $wasPending ? BillingService::STATUS_CANCELLED : BillingService::STATUS_TERMINATED,
            'terminated_at' => now(),
            'last_error' => null,
        ]);
        $this->cancelOpenInvoices($service, $actor);
        AuditLog::record('billing.terminated', $service, ['reason' => $reason], $actor);

        if ($reason === 'unpaid' && $service->user) {
            $this->mailer->send($service->user, 'service.terminated_unpaid', ['service' => ['name' => $service->name]]);
        }

        return true;
    }

    /** Rezygnacja klienta: okresowe — z końcem opłaconego okresu, godzinowe — od razu. */
    public function cancel(BillingService $service, User $actor, bool $immediately = false): void
    {
        if ($service->status === BillingService::STATUS_PENDING) {
            $service->update(['status' => BillingService::STATUS_CANCELLED]);
            $this->cancelOpenInvoices($service, $actor);
            AuditLog::record('billing.cancelled', $service, [], $actor);

            return;
        }
        if ($immediately || $service->metered()) {
            if (! $this->terminate($service, 'cancelled', $actor)) {
                throw new \DomainException(__('Nie udało się teraz usunąć usługi — spróbuj za chwilę.'));
            }

            return;
        }
        $service->update(['cancel_at_period_end' => true]);
        $this->cancelOpenInvoices($service, $actor);
        AuditLog::record('billing.cancel_requested', $service, ['at' => $service->next_due_at?->toIso8601String()], $actor);
    }

    // --- harmonogram -------------------------------------------------------------------

    /** Wszystkie zadania okresowe — bezpieczne przy wielokrotnym uruchomieniu. */
    public function run(): array
    {
        return [
            'synced' => $this->syncDeleted(),
            'metered' => $this->chargeMetered(),
            'renewals' => $this->createRenewals(),
            'expired' => $this->endCancelled(),
            'overdue' => $this->processOverdue(),
            'low_balance' => $this->warnLowBalance(),
        ];
    }

    /** Opłaty godzinowe/dzienne z portfela; saldo poniżej zera → zawieszenie usług godzinowych. */
    public function chargeMetered(): int
    {
        $charged = 0;
        $users = [];
        BillingService::query()->with('user')->where('status', BillingService::STATUS_ACTIVE)
            ->where('metered', true)->where('next_due_at', '<=', now())
            ->each(function (BillingService $service) use (&$charged, &$users) {
                $due = $service->next_due_at;
                $amount = Billing::gross($service->amount);
                for ($i = 0; $i < self::MAX_CATCH_UP && $due->lessThanOrEqualTo(now()); $i++) {
                    DB::transaction(function () use ($service, $amount, $due) {
                        if ($amount > 0) {
                            $this->wallet->debit($service->user, $amount, 'usage', $this->usageDescription($service, $due), ['billing_service_id' => $service->id], true);
                        }
                        $service->update(['next_due_at' => Cycle::add($due, $service->cycle)]);
                    });
                    $due = $service->next_due_at;
                    $charged++;
                }
                $users[$service->user_id] = $service->user;
            });

        foreach ($users as $user) {
            if ($this->wallet->balance($user) < 0) {
                BillingService::query()->where('user_id', $user->id)->where('status', BillingService::STATUS_ACTIVE)
                    ->where('metered', true)->get()
                    ->each(fn (BillingService $s) => $this->suspend($s, 'unpaid'));
            }
        }

        return $charged;
    }

    /** Faktury odnowienia N dni przed końcem okresu; opłacane z portfela, gdy są środki. */
    public function createRenewals(): int
    {
        $created = 0;
        $horizon = now()->addDays(Billing::int('renewal_days'));
        BillingService::query()->with('user')->whereIn('status', BillingService::LIVE)
            ->where('metered', false)->where('cancel_at_period_end', false)
            ->whereNotNull('next_due_at')->where('next_due_at', '<=', $horizon)
            ->each(function (BillingService $service) use (&$created) {
                $start = $service->next_due_at->copy();
                $exists = InvoiceItem::query()->where('billing_service_id', $service->id)->where('kind', 'renewal')
                    ->where('period_start', $start)
                    ->whereHas('invoice', fn ($q) => $q->where('status', '!=', Invoice::STATUS_CANCELLED))->exists();
                if ($exists) {
                    return;
                }
                $end = Cycle::add($start, $service->cycle);
                $items = [[
                    'kind' => 'renewal',
                    'description' => $this->periodDescription($service, $start, $end),
                    'amount' => $service->amount,
                    'billing_service_id' => $service->id,
                    'period_start' => $start,
                    'period_end' => $end,
                ]];
                $total = $this->previewTotal($items);
                $autoPay = $total > 0 && Billing::get('auto_pay') === '1' && $this->wallet->balance($service->user) >= $total;
                $invoice = $this->invoices->create($service->user, $items, Invoice::TYPE_SERVICE, $start->isPast() ? now() : $start, ! $autoPay && $total > 0);
                if ($total === 0) {
                    $this->invoices->markPaid($invoice, 'free', 'free-'.$invoice->id, 0);
                } elseif ($autoPay) {
                    try {
                        $this->invoices->payWithWallet($invoice);
                    } catch (\DomainException) {
                        $this->mailer->send($service->user, 'invoice.created', $this->invoices->vars($invoice));
                    }
                }
                $created++;
            });

        return $created;
    }

    /** Usługi zrezygnowane z końcem okresu — usuwamy po jego upływie. */
    public function endCancelled(): int
    {
        $ended = 0;
        BillingService::query()->whereIn('status', BillingService::LIVE)->where('cancel_at_period_end', true)
            ->where('next_due_at', '<=', now())
            ->each(function (BillingService $service) use (&$ended) {
                $ended += (int) $this->terminate($service, 'cancelled');
            });

        return $ended;
    }

    /** Przypomnienia, faktury po terminie, zawieszenie i usunięcie za brak płatności. */
    public function processOverdue(): int
    {
        $actions = 0;

        // Przypomnienie przed terminem (nie dla faktur wystawionych przed chwilą).
        Invoice::query()->with('user')->where('status', Invoice::STATUS_UNPAID)->whereNull('reminded_at')
            ->where('due_at', '>', now())->where('due_at', '<=', now()->addDays(Billing::int('reminder_days')))
            ->where('created_at', '<=', now()->subDay())
            ->each(function (Invoice $invoice) use (&$actions) {
                $invoice->update(['reminded_at' => now()]);
                $this->mailer->send($invoice->user, 'invoice.reminder', $this->invoices->vars($invoice));
                $actions++;
            });

        Invoice::query()->with('user')->where('status', Invoice::STATUS_UNPAID)->whereNull('overdue_notified_at')
            ->where('due_at', '<', now())
            ->each(function (Invoice $invoice) use (&$actions) {
                $invoice->update(['overdue_notified_at' => now()]);
                $this->mailer->send($invoice->user, 'invoice.overdue', $this->invoices->vars($invoice));
                $actions++;
            });

        // Zawieszenie usług z fakturą przeterminowaną o więcej niż N dni.
        $suspendBefore = now()->subDays(Billing::int('suspend_days'));
        InvoiceItem::query()->with('service')->whereNotNull('billing_service_id')
            ->whereHas('invoice', fn ($q) => $q->where('status', Invoice::STATUS_UNPAID)->where('type', Invoice::TYPE_SERVICE)->where('due_at', '<', $suspendBefore))
            ->get()->pluck('service')->filter()->unique('id')
            ->each(function (BillingService $service) use (&$actions) {
                if ($service->status === BillingService::STATUS_ACTIVE) {
                    $this->suspend($service, 'unpaid');
                    $actions++;
                }
            });

        // Usunięcie po N dniach zawieszenia za brak płatności.
        BillingService::query()->where('status', BillingService::STATUS_SUSPENDED)->where('suspend_reason', 'unpaid')
            ->where('suspended_at', '<', now()->subDays(Billing::int('terminate_days')))
            ->each(function (BillingService $service) use (&$actions) {
                $actions += (int) $this->terminate($service, 'unpaid');
            });

        return $actions;
    }

    /** E-mail, gdy saldo starczy na mniej niż N godzin usług godzinowych (raz do doładowania). */
    public function warnLowBalance(): int
    {
        $threshold = Billing::int('low_balance_hours');
        if ($threshold === 0) {
            return 0;
        }
        $sent = 0;
        $byUser = BillingService::query()->where('status', BillingService::STATUS_ACTIVE)
            ->where('metered', true)->get()->groupBy('user_id');
        foreach ($byUser as $userId => $services) {
            $user = User::query()->find($userId);
            if ($user === null || $user->wallet_notified_at !== null) {
                continue;
            }
            $perHour = $services->sum(fn (BillingService $s) => intdiv(Billing::gross($s->amount), Cycle::hours($s->cycle)));
            $hoursLeft = $perHour > 0 ? intdiv(max(0, $user->wallet_balance), $perHour) : PHP_INT_MAX;
            if ($hoursLeft < $threshold) {
                $user->forceFill(['wallet_notified_at' => now()])->save();
                $this->mailer->send($user, 'wallet.low_balance', [
                    'hours_left' => (string) $hoursLeft,
                    'wallet' => ['balance' => Money::format($user->wallet_balance), 'url' => route('panel.billing.wallet')],
                ]);
                $sent++;
            }
        }

        return $sent;
    }

    /** Maszyna/aplikacja usunięta poza billingiem (przez klienta lub personel) → usługa zakończona. */
    public function syncDeleted(): int
    {
        $synced = 0;
        BillingService::query()->whereIn('status', BillingService::LIVE)->get()
            ->each(function (BillingService $service) use (&$synced) {
                $server = $service->server_id ? Server::withTrashed()->find($service->server_id) : null;
                $app = $service->app_server_id ? AppServer::query()->find($service->app_server_id) : null;
                $gone = ($service->server_id === null && $service->app_server_id === null)
                    || ($service->server_id !== null && ($server === null || $server->trashed()))
                    || ($service->app_server_id !== null && $app === null);
                if ($gone) {
                    $service->update(['status' => BillingService::STATUS_TERMINATED, 'terminated_at' => now()]);
                    $this->cancelOpenInvoices($service, null);
                    AuditLog::record('billing.terminated', $service, ['reason' => 'resource_deleted'], null);
                    $synced++;
                }
            });

        return $synced;
    }

    // --- pomocnicze ------------------------------------------------------------------------

    private function hasOverdueInvoice(BillingService $service): bool
    {
        return InvoiceItem::query()->where('billing_service_id', $service->id)
            ->whereHas('invoice', fn ($q) => $q->where('status', Invoice::STATUS_UNPAID)->where('due_at', '<', now()))->exists();
    }

    /** Nieopłacone faktury dotyczące wyłącznie tej usługi przestają obowiązywać. */
    private function cancelOpenInvoices(BillingService $service, ?User $actor): void
    {
        $invoiceIds = InvoiceItem::query()->where('billing_service_id', $service->id)->pluck('invoice_id')->unique();
        Invoice::query()->whereIn('id', $invoiceIds)->where('status', Invoice::STATUS_UNPAID)->get()
            ->each(function (Invoice $invoice) use ($service, $actor) {
                $others = $invoice->items()->where(fn ($q) => $q->whereNull('billing_service_id')->orWhere('billing_service_id', '!=', $service->id))->exists();
                if (! $others) {
                    $invoice->update(['status' => Invoice::STATUS_CANCELLED]);
                    AuditLog::record('invoice.cancelled', $invoice, ['number' => $invoice->number, 'reason' => 'service_ended'], $actor);
                }
            });
    }

    private function periodDescription(BillingService $service, Carbon $start, Carbon $end): string
    {
        // Okresy godzinowe i dzienne z godziną — inaczej „15.10 – 15.10” nic nie mówi.
        $format = in_array(Cycle::unit($service->cycle), ['h', 'd'], true) ? 'd.m.Y H:i' : 'd.m.Y';

        return __(':name (:from – :to)', ['name' => $service->name, 'from' => $start->format($format), 'to' => $end->format($format)]);
    }

    private function usageDescription(BillingService $service, Carbon $from): string
    {
        return __(':name — :cycle od :from', ['name' => $service->name, 'cycle' => Cycle::label($service->cycle), 'from' => $from->format('d.m.Y H:i')]);
    }
}
