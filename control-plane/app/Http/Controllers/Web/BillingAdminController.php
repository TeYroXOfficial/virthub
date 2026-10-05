<?php

namespace App\Http\Controllers\Web;

use App\Domain\Billing\Billing;
use App\Domain\Billing\Cycle;
use App\Domain\Billing\InvoiceManager;
use App\Domain\Billing\Money;
use App\Domain\Billing\ServiceManager;
use App\Domain\Billing\Wallet;
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

    public function index(): View
    {
        $paid = Invoice::query()->where('status', Invoice::STATUS_PAID)->where('type', Invoice::TYPE_SERVICE);

        return view('panel.admin.billing.index', [
            'stats' => [
                'month' => (int) (clone $paid)->where('paid_at', '>=', now()->startOfMonth())->sum('total'),
                'unpaid' => (int) Invoice::query()->where('status', Invoice::STATUS_UNPAID)->sum('total'),
                'overdue' => Invoice::query()->where('status', Invoice::STATUS_UNPAID)->where('due_at', '<', now())->count(),
                'active' => BillingService::query()->where('status', BillingService::STATUS_ACTIVE)->count(),
                'suspended' => BillingService::query()->where('status', BillingService::STATUS_SUSPENDED)->count(),
                'wallets' => (int) User::query()->sum('wallet_balance'),
                'metered' => (int) WalletTransaction::query()->where('type', 'usage')->where('created_at', '>=', now()->startOfMonth())->sum('amount'),
            ],
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
        ]);
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
        [$data, $prices] = $this->productData($request);
        $product = DB::transaction(function () use ($data, $prices) {
            $product = Product::create($data);
            $this->syncPrices($product, $prices);

            return $product;
        });
        AuditLog::record('billing.product_created', $product, ['name' => $product->name], $request->user());

        return redirect()->route('panel.admin.billing.catalog')->with('status', __('Produkt dodany.'));
    }

    public function updateProduct(Request $request, Product $product): RedirectResponse
    {
        [$data, $prices] = $this->productData($request);
        DB::transaction(function () use ($product, $data, $prices) {
            $product->update($data);
            $this->syncPrices($product, $prices);
        });
        AuditLog::record('billing.product_updated', $product, ['name' => $product->name], $request->user());

        return redirect()->route('panel.admin.billing.catalog')->with('status', __('Produkt zapisany. Działające usługi zachowują swoją cenę.'));
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
                'resume' => $service->update(['cancel_at_period_end' => false]),
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
            'categories' => ProductCategory::query()->ordered()->get(),
            'packages' => VpsPackage::query()->orderBy('vcpu')->orderBy('ram_mb')->get(),
            'plans' => AppPlan::query()->orderBy('memory_mb')->get(),
            'eggs' => AppEgg::query()->active()->orderBy('category')->orderBy('name')->get(),
            'locations' => HypervisorGroup::query()->ordered()->get(),
            'prices' => $product->exists ? $product->priceMap() : [],
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
            'type' => ['required', Rule::in([Product::TYPE_VPS, Product::TYPE_APP])],
            'vps_package_id' => ['nullable', 'required_if:type,vps', 'integer', 'exists:vps_packages,id'],
            'app_plan_id' => ['nullable', 'required_if:type,app', 'integer', 'exists:app_plans,id'],
            'app_egg_ids' => ['nullable', 'array'],
            'app_egg_ids.*' => ['integer', 'exists:app_eggs,id'],
            'hypervisor_group_ids' => ['nullable', 'array'],
            'hypervisor_group_ids.*' => ['integer', 'exists:hypervisor_groups,id'],
            'setup_fee' => [$moneyRule],
            'stock' => ['nullable', 'integer', 'min:0'],
            'sort_order' => ['nullable', 'integer', 'between:0,10000'],
            'prices' => ['array'],
            'prices.*' => [$moneyRule],
        ], [], ['prices.*' => __('cena')]);

        $prices = [];
        foreach (Cycle::ALL as $cycle) {
            $raw = $data['prices'][$cycle] ?? null;
            $prices[$cycle] = $raw === null || $raw === '' ? null : Money::parse((string) $raw);
        }
        if (array_filter($prices, fn ($p) => $p !== null) === []) {
            throw ValidationException::withMessages(['prices' => __('Podaj cenę dla co najmniej jednego cyklu.')]);
        }

        $isVps = $data['type'] === Product::TYPE_VPS;

        return [[
            'product_category_id' => $data['product_category_id'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'type' => $data['type'],
            'vps_package_id' => $isVps ? $data['vps_package_id'] : null,
            'app_plan_id' => $isVps ? null : $data['app_plan_id'],
            'app_egg_ids' => $isVps || empty($data['app_egg_ids']) ? null : array_map('intval', $data['app_egg_ids']),
            'hypervisor_group_ids' => empty($data['hypervisor_group_ids']) ? null : array_map('intval', $data['hypervisor_group_ids']),
            'setup_fee' => ($data['setup_fee'] ?? '') === '' ? 0 : Money::parse((string) $data['setup_fee']),
            'stock' => $data['stock'] ?? null,
            'sort_order' => $data['sort_order'] ?? 0,
            'is_active' => $request->boolean('is_active'),
        ], $prices];
    }

    /** @param  array<string, ?int>  $prices */
    private function syncPrices(Product $product, array $prices): void
    {
        foreach ($prices as $cycle => $amount) {
            if ($amount === null) {
                $product->prices()->where('cycle', $cycle)->delete();
            } else {
                $product->prices()->updateOrCreate(['cycle' => $cycle], ['amount' => $amount]);
            }
        }
    }

    private function categoryData(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'sort_order' => ['nullable', 'integer', 'between:0,10000'],
        ]);

        return $data + ['is_active' => $request->boolean('is_active'), 'sort_order' => $data['sort_order'] ?? 0];
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
