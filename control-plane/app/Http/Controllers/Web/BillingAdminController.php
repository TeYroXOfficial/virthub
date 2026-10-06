<?php

namespace App\Http\Controllers\Web;

use App\Domain\Agent\AgentException;
use App\Domain\Apps\AppProvisioner;
use App\Domain\Billing\Billing;
use App\Domain\Billing\BillingStats;
use App\Domain\Billing\Cycle;
use App\Domain\Billing\InvoiceManager;
use App\Domain\Billing\Money;
use App\Domain\Billing\ServiceManager;
use App\Domain\Billing\Wallet;
use App\Domain\External\ExternalServerManager;
use App\Http\Controllers\Controller;
use App\Models\AppEgg;
use App\Models\AppPlan;
use App\Models\AuditLog;
use App\Models\BillingService;
use App\Models\HypervisorGroup;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProviderAccount;
use App\Models\User;
use App\Models\VpsPackage;
use App\Models\WalletTransaction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Administracja → Billing: ustawienia, katalog, usługi, faktury i portfele klientów. */
class BillingAdminController extends Controller
{
    public function __construct(
        private readonly InvoiceManager $invoices,
        private readonly ServiceManager $services,
        private readonly Wallet $wallet,
    ) {}

    // --- pulpit i ustawienia -------------------------------------------------------------

    public function index(BillingStats $billingStats): View
    {
        return view('panel.admin.billing.index', [
            'stats' => $billingStats->summary(),
            'daily' => $billingStats->daily(30),
            'byProduct' => $billingStats->byProduct(),
            'recent' => Payment::query()->with('invoice.user')->latest('id')->limit(10)->get(),
            'enabled' => Billing::enabled(),
        ]);
    }

    public function settings(): View
    {
        return view('panel.admin.billing.settings', ['s' => collect(Billing::DEFAULTS)->mapWithKeys(fn ($v, $k) => [$k => Billing::get($k)])->all()]);
    }

    public function saveSettings(Request $request): RedirectResponse
    {
        $money = fn (string $attr, $value, $fail) => Money::valid((string) $value) && Money::parse((string) $value) >= 0 ? null : $fail(__('Podaj poprawną kwotę.'));
        $data = $request->validate([
            'currency' => ['required', 'string', 'size:3', 'alpha'],
            'tax_rate' => ['required', 'numeric', 'between:0,100'],
            'seller_name' => ['nullable', 'string', 'max:200'],
            'seller_address' => ['nullable', 'string', 'max:500'],
            'seller_tax_id' => ['nullable', 'string', 'max:50'],
            'invoice_prefix' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9\/\-_.{}]+$/'],
            'invoice_notes' => ['nullable', 'string', 'max:1000'],
            'due_days' => ['required', 'integer', 'between:0,90'],
            'renewal_days' => ['required', 'integer', 'between:0,60'],
            'reminder_days' => ['required', 'integer', 'between:0,30'],
            'suspend_days' => ['required', 'integer', 'between:0,90'],
            'terminate_days' => ['required', 'integer', 'between:1,365'],
            'min_topup' => ['required', $money],
            'max_topup' => ['required', $money],
            'min_balance_metered' => ['required', $money],
            'low_balance_hours' => ['required', 'integer', 'between:0,720'],
            'inactive_delete_count' => ['nullable', 'required_with:inactive_delete_unit', 'integer', 'min:1', 'max:720'],
            'inactive_delete_unit' => ['nullable', 'required_with:inactive_delete_count', Rule::in(Cycle::UNITS)],
        ]);
        if (! empty($data['inactive_delete_count'])) {
            try {
                $data['inactive_delete'] = Cycle::code((int) $data['inactive_delete_count'], $data['inactive_delete_unit']);
            } catch (\InvalidArgumentException) {
                throw ValidationException::withMessages(['inactive_delete_count' => __('Za długi okres.')]);
            }
        }
        unset($data['inactive_delete_count'], $data['inactive_delete_unit']);
        $data['currency'] = strtoupper($data['currency']);
        foreach (['enabled', 'prices_include_tax', 'auto_pay'] as $flag) {
            $data[$flag] = $request->boolean($flag);
        }
        Billing::save($data);
        AuditLog::record('settings.billing', null, ['enabled' => $data['enabled']], $request->user());

        return back()->with('status', __('Ustawienia billingu zapisane.'));
    }

    // --- katalog -----------------------------------------------------------------------

    public function catalog(): View
    {
        return view('panel.admin.billing.catalog', [
            'categories' => ProductCategory::query()->ordered()->with(['products' => fn ($q) => $q->with('prices', 'package', 'plan')->withCount(['services as live_count' => fn ($s) => $s->whereIn('status', BillingService::LIVE)])])->get(),
        ]);
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        $data = $this->categoryData($request);
        ProductCategory::create($data + ['slug' => $this->uniqueSlug($data['name'])]);

        return back()->with('status', __('Kategoria dodana.'));
    }

    public function updateCategory(Request $request, ProductCategory $category): RedirectResponse
    {
        $category->update($this->categoryData($request));

        return back()->with('status', __('Kategoria zapisana.'));
    }

    public function destroyCategory(ProductCategory $category): RedirectResponse
    {
        if ($category->products()->exists()) {
            return back()->withErrors(['category' => __('Kategoria ma produkty — przenieś je albo usuń najpierw.')]);
        }
        $category->delete();

        return back()->with('status', __('Kategoria usunięta.'));
    }

    public function createProduct(Request $request): View
    {
        return $this->productForm(new Product([
            'type' => Product::TYPE_VPS, 'is_active' => true, 'setup_fee' => 0,
            'product_category_id' => $request->integer('category') ?: null,
        ]));
    }

    public function editProduct(Product $product): View
    {
        return $this->productForm($product->load('prices'));
    }

    public function storeProduct(Request $request): RedirectResponse
    {
        [$data, $prices, $replace] = $this->productData($request);
        $product = DB::transaction(function () use ($data, $prices, $replace) {
            $product = Product::create($data);
            $this->syncPrices($product, $prices, $replace);

            return $product;
        });
        AuditLog::record('billing.product_created', $product, ['name' => $product->name], $request->user());

        return redirect()->route('panel.admin.billing.catalog')->with('status', __('Produkt dodany.'));
    }

    public function updateProduct(Request $request, Product $product): RedirectResponse
    {
        [$data, $prices, $replace] = $this->productData($request);
        DB::transaction(function () use ($product, $data, $prices, $replace) {
            $product->update($data);
            $this->syncPrices($product, $prices, $replace);
        });
        AuditLog::record('billing.product_updated', $product, ['name' => $product->name], $request->user());

        $product->refresh()->load('prices', 'package', 'plan');
        $services = $product->services()->whereIn('status', [BillingService::STATUS_PENDING, ...BillingService::LIVE])
            ->with('server', 'appServer')->get();
        $messages = [__('Produkt zapisany.')];
        $errors = [];

        // Nowa cena dla działających usług — od najbliższej opłaty (faktury już wystawione zostają).
        if ($request->boolean('apply_prices')) {
            $prices = $product->configuredPrices();
            $changed = 0;
            $skipped = 0;
            foreach ($services as $service) {
                $price = $prices[$service->cycle] ?? null;
                if ($price === null) {
                    $skipped++;
                } elseif ($price !== $service->amount) {
                    AuditLog::record('billing.service_price', $service, ['from' => $service->amount, 'to' => $price], $request->user());
                    $service->update(['amount' => $price]);
                    $changed++;
                }
            }
            $messages[] = __('Nowa cena w :count usługach (od najbliższej opłaty).', ['count' => $changed]);
            if ($skipped > 0) {
                $messages[] = __(':count usług ma okres, którego produkt już nie oferuje — zachowały dotychczasową cenę.', ['count' => $skipped]);
            }
        }

        // Wymóg potwierdzania aktywności dla już działających usług.
        if ($request->boolean('apply_keepalive')) {
            $count = 0;
            foreach ($services->where('status', '!=', BillingService::STATUS_PENDING) as $service) {
                $service->update([
                    'keepalive_interval' => $product->keepalive_interval,
                    'keepalive_window' => $product->keepalive_window,
                    'keepalive_delete_after' => $product->keepalive_delete_after,
                    // Nowy wymóg liczy się od teraz — nikt nie traci usługi od razu.
                    'keepalive_until' => $product->keepalive_interval ? Cycle::add(now(), $product->keepalive_interval) : null,
                    'keepalive_notified_at' => null,
                ]);
                if (! $product->keepalive_interval && $service->status === BillingService::STATUS_SUSPENDED && $service->suspend_reason === 'inactive') {
                    $this->services->unsuspend($service, $request->user());
                }
                $count++;
            }
            $messages[] = $product->keepalive_interval
                ? __('Wymóg potwierdzania aktywności ustawiony w :count usługach.', ['count' => $count])
                : __('Wymóg potwierdzania aktywności zdjęty z :count usług.', ['count' => $count]);
        }

        // Zasoby planu na działające aplikacje; maszyn VPS nie da się zmienić bez zatrzymania.
        if ($request->boolean('apply_resources')) {
            if ($product->type === Product::TYPE_APP && $product->plan) {
                $plan = $product->plan;
                $updated = 0;
                foreach ($services->pluck('appServer')->filter() as $app) {
                    try {
                        app(AppProvisioner::class)->updateResources($app, $plan->memory_mb, $plan->cpu_percent, $plan->disk_mb, $request->user());
                        $updated++;
                    } catch (\DomainException|AgentException) {
                        $errors[] = $app->name;
                    }
                }
                $messages[] = __('Zasoby planu :plan ustawione w :count aplikacjach.', ['plan' => $plan->name, 'count' => $updated]);
            } elseif ($product->type === Product::TYPE_VPS && $product->package) {
                $package = $product->package;
                $differs = $services->pluck('server')->filter()->filter(fn ($s) => $s->vps_package_id !== $package->id || $s->vcpu !== $package->vcpu
                    || $s->ram_mb !== $package->ram_mb || $s->disk_gb !== $package->disk_gb)->count();
                $messages[] = __(':count maszyn ma inne parametry niż pakiet :package — zmień je przyciskiem „Zmień pakiet” na stronie maszyny (wymaga zatrzymania).', ['count' => $differs, 'package' => $package->name]);
            }
        }

        $redirect = redirect()->route('panel.admin.billing.products.edit', $product)->with('status', implode(' ', $messages));

        return $errors === [] ? $redirect : $redirect->withErrors(['product' => __('Nie udało się wysłać zmian na węzeł dla: :apps. Panel ma nowe limity — węzeł dostanie je przy najbliższym restarcie lub reinstalacji.', ['apps' => implode(', ', array_slice($errors, 0, 10))])]);
    }

    public function destroyProduct(Product $product): RedirectResponse
    {
        if ($product->services()->whereIn('status', [BillingService::STATUS_PENDING, ...BillingService::LIVE])->exists()) {
            return back()->withErrors(['product' => __('Produkt ma aktywne usługi — wyłącz go zamiast usuwać.')]);
        }
        $product->delete();

        return back()->with('status', __('Produkt usunięty.'));
    }

    // --- usługi --------------------------------------------------------------------------

    public function services(Request $request): View
    {
        $status = $request->query('status');
        $q = trim((string) $request->query('q'));
        $query = BillingService::query()->with('user', 'product')->latest('id');
        if (in_array($status, [BillingService::STATUS_PENDING, BillingService::STATUS_ACTIVE, BillingService::STATUS_SUSPENDED, BillingService::STATUS_TERMINATED, BillingService::STATUS_CANCELLED], true)) {
            $query->where('status', $status);
        }
        if ($q !== '') {
            $like = '%'.addcslashes($q, '%_\\').'%';
            $query->where(fn ($w) => $w->where('name', 'like', $like)->orWhereHas('user', fn ($u) => $u->where('email', 'like', $like)));
        }

        return view('panel.admin.billing.services', ['services' => $query->paginate(30)->withQueryString(), 'status' => $status, 'q' => $q]);
    }

    public function service(BillingService $service): View
    {
        $service->load('user', 'product', 'server', 'appServer', 'invoiceItems.invoice');

        return view('panel.admin.billing.service', ['service' => $service]);
    }

    public function serviceAction(Request $request, BillingService $service): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['suspend', 'unsuspend', 'terminate', 'cancel_end', 'resume', 'activate'])],
        ]);
        $actor = $request->user();

        try {
            match ($data['action']) {
                'suspend' => $this->services->suspend($service, 'admin', $actor),
                'unsuspend' => $this->services->unsuspend($service, $actor),
                'terminate' => $this->services->terminate($service, 'admin', $actor) ?: throw new \DomainException($service->fresh()->last_error ?: __('Nie udało się usunąć usługi.')),
                'cancel_end' => $service->update(['cancel_at_period_end' => true]),
                'resume' => $service->renews ? $service->update(['cancel_at_period_end' => false]) : throw new \DomainException(__('Usługa jest jednorazowa — kończy się z końcem okresu.')),
                // Uruchomienie bez płatności (np. usługa testowa, płatność poza panelem).
                'activate' => $this->services->activate($service, 0, $actor) ?: throw new \DomainException($service->fresh()->last_error ?: __('Usługa nie czeka na uruchomienie.')),
            };
        } catch (\DomainException $e) {
            return back()->withErrors(['service' => $e->getMessage()]);
        }
        AuditLog::record('billing.admin_'.$data['action'], $service, [], $actor);

        return back()->with('status', __('Zapisano.'));
    }

    public function updateService(Request $request, BillingService $service): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', fn ($a, $v, $fail) => Money::valid((string) $v) && Money::parse((string) $v) >= 0 ? null : $fail(__('Podaj poprawną kwotę.'))],
            'next_due_at' => ['nullable', 'date'],
            'name' => ['required', 'string', 'max:150'],
        ]);
        $service->update([
            'name' => $data['name'],
            'amount' => Money::parse((string) $data['amount']),
            'next_due_at' => $data['next_due_at'] ? Carbon::parse($data['next_due_at']) : $service->next_due_at,
        ]);
        AuditLog::record('billing.service_updated', $service, ['amount' => $service->amount, 'next_due_at' => $service->next_due_at?->toIso8601String()], $request->user());

        return back()->with('status', __('Usługa zapisana.'));
    }

    // --- faktury -------------------------------------------------------------------------

    public function invoices(Request $request): View
    {
        $status = $request->query('status');
        $q = trim((string) $request->query('q'));
        $query = Invoice::query()->with('user')->latest('id');
        if ($status === 'overdue') {
            $query->where('status', Invoice::STATUS_UNPAID)->where('due_at', '<', now());
        } elseif (in_array($status, [Invoice::STATUS_UNPAID, Invoice::STATUS_PAID, Invoice::STATUS_CANCELLED, Invoice::STATUS_REFUNDED], true)) {
            $query->where('status', $status);
        }
        if ($q !== '') {
            $like = '%'.addcslashes($q, '%_\\').'%';
            $query->where(fn ($w) => $w->where('number', 'like', $like)->orWhereHas('user', fn ($u) => $u->where('email', 'like', $like)));
        }

        return view('panel.admin.billing.invoices', ['invoices' => $query->paginate(30)->withQueryString(), 'status' => $status, 'q' => $q]);
    }

    public function invoice(Invoice $invoice): View
    {
        $invoice->load('items', 'payments', 'user');

        return view('panel.billing.invoice', ['invoice' => $invoice, 'balance' => (int) $invoice->user?->wallet_balance, 'gateways' => [], 'admin' => true]);
    }

    public function invoiceAction(Request $request, Invoice $invoice): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['mark_paid', 'cancel', 'refund', 'wallet'])],
            'reference' => ['nullable', 'string', 'max:150'],
        ]);
        $actor = $request->user();

        try {
            match ($data['action']) {
                // Identyfikator z numerem faktury — ten sam tytuł przelewu na dwóch fakturach nie myli płatności.
                'mark_paid' => $this->invoices->markPaid($invoice, 'manual', $invoice->id.':'.($data['reference'] ?: Str::random(12)), null, null, $actor, ['by' => $actor->email, 'reference' => $data['reference'] ?? null]),
                'wallet' => $this->invoices->payWithWallet($invoice, $actor),
                'cancel' => $this->invoices->cancel($invoice, $actor),
                'refund' => $this->invoices->refundToWallet($invoice, $actor),
            };
        } catch (\DomainException $e) {
            return back()->withErrors(['invoice' => $e->getMessage()]);
        }

        return back()->with('status', __('Faktura zaktualizowana.'));
    }

    // --- portfele ------------------------------------------------------------------------

    public function customers(Request $request): View
    {
        $q = trim((string) $request->query('q'));
        $query = User::query()->orderByDesc('wallet_balance')->withCount(['billingServices as live_count' => fn ($s) => $s->whereIn('status', BillingService::LIVE)]);
        if ($q !== '') {
            $like = '%'.addcslashes($q, '%_\\').'%';
            $query->where(fn ($w) => $w->where('email', 'like', $like)->orWhere('name', 'like', $like));
        }

        return view('panel.admin.billing.customers', ['users' => $query->paginate(30)->withQueryString(), 'q' => $q]);
    }

    public function customer(User $user): View
    {
        return view('panel.admin.billing.customer', [
            'customer' => $user,
            'transactions' => WalletTransaction::query()->where('user_id', $user->id)->latest('id')->paginate(25),
            'services' => BillingService::query()->where('user_id', $user->id)->latest('id')->limit(20)->get(),
            'invoices' => Invoice::query()->where('user_id', $user->id)->latest('id')->limit(20)->get(),
        ]);
    }

    public function adjustWallet(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'string', 'max:20', fn ($a, $v, $fail) => Money::valid($v) && Money::parse($v) !== 0 ? null : $fail(__('Podaj kwotę różną od zera, np. 50 albo -12,50.'))],
            'description' => ['required', 'string', 'max:200'],
        ]);
        $amount = Money::parse($data['amount']);
        $this->wallet->adjust($user, $amount, $data['description'], $request->user());
        AuditLog::record('billing.wallet_adjusted', $user, ['amount' => $amount, 'description' => $data['description']], $request->user());
        if ($amount > 0) {
            $this->services->resumeAfterTopup($user->fresh(), $request->user());
        }

        return back()->with('status', __('Saldo zmienione o :amount.', ['amount' => Money::format($amount)]));
    }

    // --- pomocnicze ------------------------------------------------------------------------

    private function productForm(Product $product): View
    {
        return view('panel.admin.billing.product', [
            'product' => $product,
            'liveCount' => $product->exists ? $product->services()->whereIn('status', [BillingService::STATUS_PENDING, ...BillingService::LIVE])->count() : 0,
            'categories' => ProductCategory::query()->ordered()->get(),
            'packages' => VpsPackage::query()->orderBy('vcpu')->orderBy('ram_mb')->get(),
            'plans' => AppPlan::query()->orderBy('memory_mb')->get(),
            'eggs' => AppEgg::query()->active()->orderBy('category')->orderBy('name')->get(),
            'locations' => HypervisorGroup::query()->ordered()->get(),
            'prices' => $product->exists ? $product->configuredPrices() : [],
            'providerAccounts' => ProviderAccount::query()->where('is_active', true)->orderBy('name')->get()->filter->driverAvailable()->values(),
        ]);
    }

    /** @return array{0: array<string, mixed>, 1: array<string, ?int>} */
    private function productData(Request $request): array
    {
        $moneyRule = fn ($attr, $value, $fail) => $value === null || $value === '' || (Money::valid((string) $value) && Money::parse((string) $value) >= 0)
            ? null : $fail(__('Podaj poprawną kwotę.'));
        $data = $request->validate([
            'product_category_id' => ['required', 'integer', 'exists:product_categories,id'],
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'type' => ['required', Rule::in([Product::TYPE_VPS, Product::TYPE_APP, Product::TYPE_EXTERNAL])],
            'provider_account_id' => ['nullable', 'required_if:type,external', 'integer', 'exists:provider_accounts,id'],
            'ext_location' => ['nullable', 'required_if:type,external', 'string', 'max:100'],
            'ext_plan' => ['nullable', 'required_if:type,external', 'string', 'max:100'],
            'ext_cpu' => ['nullable', 'integer', 'min:1', 'max:512'],
            'ext_ram_mb' => ['nullable', 'integer', 'min:256', 'max:4194304'],
            'ext_disk_gb' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'ext_images' => ['nullable', 'required_if:type,external', 'array'],
            'ext_images.*' => ['string', 'max:100'],
            'vps_package_id' => ['nullable', 'required_if:type,vps', 'integer', 'exists:vps_packages,id'],
            'app_plan_id' => ['nullable', 'required_if:type,app', 'integer', 'exists:app_plans,id'],
            'app_egg_ids' => ['nullable', 'array'],
            'app_egg_ids.*' => ['integer', 'exists:app_eggs,id'],
            'hypervisor_group_ids' => ['nullable', 'array'],
            'hypervisor_group_ids.*' => ['integer', 'exists:hypervisor_groups,id'],
            'setup_fee' => [$moneyRule],
            'stock' => ['nullable', 'integer', 'min:0'],
            'per_user_limit' => ['nullable', 'integer', 'min:1'],
            'sort_order' => ['nullable', 'integer', 'between:0,10000'],
            'cycles' => ['nullable', 'array'],
            'cycles.*' => [Rule::in(Cycle::ALL)],
            'prices' => ['array'],
            'prices.*' => [$moneyRule],
            'periods' => ['nullable', 'array', 'max:30'],
            'periods.*.count' => ['required', 'integer', 'min:1', 'max:720'],
            'periods.*.unit' => ['required', Rule::in(Cycle::UNITS)],
            'periods.*.price' => [$moneyRule],
            'periods.*.once' => ['nullable', 'boolean'],
            'keepalive' => ['nullable', 'boolean'],
            'keepalive_count' => ['nullable', 'required_if:keepalive,1', 'integer', 'min:1', 'max:720'],
            'keepalive_unit' => ['nullable', 'required_if:keepalive,1', Rule::in(Cycle::UNITS)],
            'keepalive_window_count' => ['nullable', 'integer', 'min:1', 'max:720'],
            'keepalive_window_unit' => ['nullable', Rule::in(Cycle::UNITS)],
            'keepalive_delete_count' => ['nullable', 'integer', 'min:1', 'max:720'],
            'keepalive_delete_unit' => ['nullable', Rule::in(Cycle::UNITS)],
        ], [], ['prices.*' => __('cena'), 'periods.*.price' => __('cena'), 'periods.*.count' => __('długość okresu')]);

        // Okresy: [kod => ['amount' => int, 'renews' => bool]]; null = usuń okres.
        if ($request->has('periods') || $request->boolean('period_rows')) {
            // Formularz panelu: dowolne okresy (liczba + jednostka), puste pole ceny = za darmo.
            $prices = [];
            foreach (array_values($data['periods'] ?? []) as $i => $row) {
                try {
                    $code = Cycle::code((int) $row['count'], $row['unit']);
                } catch (\InvalidArgumentException) {
                    throw ValidationException::withMessages(["periods.{$i}.count" => __('Najdłuższy okres to :max :unit.', ['max' => Cycle::MAX[$row['unit']], 'unit' => Cycle::unitLabels()[$row['unit']]])]);
                }
                if (isset($prices[$code])) {
                    throw ValidationException::withMessages(["periods.{$i}.count" => __('Okres :period jest na liście dwa razy.', ['period' => Cycle::duration($code)])]);
                }
                $raw = $row['price'] ?? null;
                $prices[$code] = [
                    'amount' => $raw === null || $raw === '' ? 0 : Money::parse((string) $raw),
                    'renews' => ! filter_var($row['once'] ?? false, FILTER_VALIDATE_BOOLEAN),
                ];
            }
            $replace = true;
        } else {
            // Starszy format (API, poprzednie formularze): stałe okresy z polami prices[okres].
            $choice = $request->boolean('cycle_choice');
            $offered = array_intersect(Cycle::ALL, $data['cycles'] ?? []);
            $prices = [];
            foreach (Cycle::ALL as $cycle) {
                $raw = $data['prices'][$cycle] ?? null;
                $blank = $raw === null || $raw === '';
                $amount = $choice
                    ? (in_array($cycle, $offered, true) ? ($blank ? 0 : Money::parse((string) $raw)) : null)
                    : ($blank ? null : Money::parse((string) $raw));
                $prices[$cycle] = $amount === null ? null : ['amount' => $amount, 'renews' => true];
            }
            $replace = false;
        }
        $chosen = array_keys(array_filter($prices, fn ($p) => $p !== null));
        if ($chosen === []) {
            throw ValidationException::withMessages(['prices' => __('Dodaj co najmniej jeden okres rozliczeniowy.')]);
        }
        $category = ProductCategory::query()->find($data['product_category_id']);
        if ($category && array_filter($chosen, fn ($c) => $category->allowsCycle($c)) === []) {
            throw ValidationException::withMessages(['prices' => __('Kategoria :name nie dopuszcza żadnego z wybranych okresów.', ['name' => $category->name])]);
        }

        // Potwierdzanie aktywności: ważność po kliknięciu i okno, w którym przycisk jest aktywny.
        $keepalive = $keepaliveWindow = $keepaliveDelete = null;
        if ($request->boolean('keepalive')) {
            try {
                $keepalive = Cycle::code((int) $data['keepalive_count'], $data['keepalive_unit']);
                $keepaliveWindow = ! empty($data['keepalive_window_count']) && ! empty($data['keepalive_window_unit'])
                    ? Cycle::code((int) $data['keepalive_window_count'], $data['keepalive_window_unit']) : null;
                // Puste = domyślny czas z ustawień billingu.
                $keepaliveDelete = ! empty($data['keepalive_delete_count']) && ! empty($data['keepalive_delete_unit'])
                    ? Cycle::code((int) $data['keepalive_delete_count'], $data['keepalive_delete_unit']) : null;
            } catch (\InvalidArgumentException) {
                throw ValidationException::withMessages(['keepalive_count' => __('Za długi okres.')]);
            }
            if ($keepaliveWindow !== null && Cycle::hours($keepaliveWindow) > Cycle::hours($keepalive)) {
                throw ValidationException::withMessages(['keepalive_window_count' => __('Przycisk nie może się odblokować wcześniej niż po poprzednim kliknięciu — okno musi być krótsze niż ważność.')]);
            }
        }

        $isVps = $data['type'] === Product::TYPE_VPS;
        $isApp = $data['type'] === Product::TYPE_APP;
        $external = $data['type'] === Product::TYPE_EXTERNAL ? $this->externalConfig($data) : null;

        return [[
            'keepalive_interval' => $keepalive,
            'keepalive_window' => $keepaliveWindow,
            'keepalive_delete_after' => $keepaliveDelete,
            'product_category_id' => $data['product_category_id'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'type' => $data['type'],
            'vps_package_id' => $isVps ? $data['vps_package_id'] : null,
            'app_plan_id' => $isApp ? $data['app_plan_id'] : null,
            'app_egg_ids' => ! $isApp || empty($data['app_egg_ids']) ? null : array_map('intval', $data['app_egg_ids']),
            'provider_account_id' => $external !== null ? (int) $data['provider_account_id'] : null,
            'external_config' => $external,
            'hypervisor_group_ids' => $external !== null || empty($data['hypervisor_group_ids']) ? null : array_map('intval', $data['hypervisor_group_ids']),
            'setup_fee' => ($data['setup_fee'] ?? '') === '' ? 0 : Money::parse((string) $data['setup_fee']),
            'stock' => $data['stock'] ?? null,
            'per_user_limit' => $data['per_user_limit'] ?? null,
            'sort_order' => $data['sort_order'] ?? 0,
            'is_active' => $request->boolean('is_active'),
        ], $prices, $replace];
    }

    /**
     * Konfiguracja produktu u dostawcy, sprawdzona z jego katalogiem
     * (lokalizacja, typ instancji, zasoby w granicach typu, nazwy systemów).
     *
     * @return array{location:string, plan:string, cpu:?int, ram_mb:?int, disk_gb:?int, images:list<array{id:string, name:string}>}
     */
    private function externalConfig(array $data): array
    {
        $account = ProviderAccount::query()->findOrFail($data['provider_account_id']);
        try {
            $catalog = app(ExternalServerManager::class)->catalog($account);
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['provider_account_id' => __('Nie udało się pobrać katalogu dostawcy: :error', ['error' => $e->getMessage()])]);
        }
        $plan = collect($catalog['plans'])->firstWhere('id', $data['ext_plan'])
            ?? throw ValidationException::withMessages(['ext_plan' => __('Dostawca nie ma takiego typu instancji.')]);
        if (! collect($catalog['locations'])->contains('id', $data['ext_location'])) {
            throw ValidationException::withMessages(['ext_location' => __('Dostawca nie ma takiej lokalizacji.')]);
        }
        if (! empty($plan['locations']) && ! in_array($data['ext_location'], $plan['locations'], true)) {
            throw ValidationException::withMessages(['ext_plan' => __('Ten typ instancji nie jest dostępny w wybranej lokalizacji.')]);
        }
        $resources = ['cpu' => $plan['cpu'] ?? null, 'ram_mb' => $plan['ram_mb'] ?? null, 'disk_gb' => $plan['disk_gb'] ?? null];
        if ($plan['configurable'] ?? false) {
            foreach (['cpu' => 'max_cpu', 'ram_mb' => 'max_ram_mb', 'disk_gb' => 'max_disk_gb'] as $field => $max) {
                $value = $data['ext_'.$field] ?? null;
                if ($value === null) {
                    throw ValidationException::withMessages(['ext_'.$field => __('Podaj zasoby maszyny.')]);
                }
                if (! empty($plan[$max]) && $value > $plan[$max]) {
                    throw ValidationException::withMessages(['ext_'.$field => __('Maksimum dla tego typu: :max.', ['max' => $plan[$max]])]);
                }
                $resources[$field] = (int) $value;
            }
        }
        $images = collect($catalog['images'])->whereIn('id', array_map('strval', $data['ext_images'] ?? []))
            ->map(fn ($i) => ['id' => (string) $i['id'], 'name' => (string) $i['name']])->values()->all();
        if ($images === []) {
            throw ValidationException::withMessages(['ext_images' => __('Wybierz co najmniej jeden system.')]);
        }

        return ['location' => (string) $data['ext_location'], 'plan' => (string) $data['ext_plan']] + $resources + ['images' => $images];
    }

    /**
     * @param  array<string, array{amount:int, renews:bool}|null>  $prices
     * @param  bool  $replace  usuń okresy, których nie ma na liście
     */
    private function syncPrices(Product $product, array $prices, bool $replace = false): void
    {
        if ($replace) {
            $product->prices()->whereNotIn('cycle', array_keys(array_filter($prices)))->delete();
        }
        foreach ($prices as $cycle => $price) {
            if ($price === null) {
                $product->prices()->where('cycle', $cycle)->delete();
            } else {
                $product->prices()->updateOrCreate(['cycle' => $cycle], $price);
            }
        }
    }

    private function categoryData(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'sort_order' => ['nullable', 'integer', 'between:0,10000'],
            'cycles' => ['nullable', 'array'],
            'cycles.*' => [Rule::in(Cycle::UNITS)],
        ]);
        // Kategoria ogranicza jednostki okresów (godziny, dni, tygodnie…); nic albo wszystko = bez ograniczeń.
        $units = array_values(array_intersect(Cycle::UNITS, $data['cycles'] ?? []));
        unset($data['cycles']);

        return $data + [
            'is_active' => $request->boolean('is_active'),
            'sort_order' => $data['sort_order'] ?? 0,
            'allowed_cycles' => $units === [] || count($units) === count(Cycle::UNITS) ? null : $units,
        ];
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'kategoria';
        $slug = $base;
        for ($i = 2; ProductCategory::query()->where('slug', $slug)->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }
}
