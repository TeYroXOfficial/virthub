<?php

namespace App\Http\Controllers\Web;

use App\Domain\Billing\Billing;
use App\Domain\Billing\InsufficientFunds;
use App\Domain\Billing\ServiceManager;
use App\Http\Controllers\Controller;
use App\Models\BillingService;
use App\Models\OsTemplate;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Rules\SshPublicKey;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Sklep klienta: kategorie → produkt → konfiguracja (cykl, lokalizacja, system) → zamówienie. */
class StoreController extends Controller
{
    public function __construct(private readonly ServiceManager $services) {}

    public function index(): View
    {
        abort_unless(Billing::enabled(), 404);

        $categories = ProductCategory::query()->where('is_active', true)->ordered()
            ->with(['products' => fn ($q) => $q->where('is_active', true)->with('prices', 'package', 'plan')])
            ->get()
            ->each(fn (ProductCategory $c) => $c->setRelation('products', $c->products->filter(fn (Product $p) => $p->deliverable() && $p->priceMap() !== [])->values()))
            ->filter(fn (ProductCategory $c) => $c->products->isNotEmpty())
            ->values();

        return view('panel.store.index', ['categories' => $categories]);
    }

    public function show(Request $request, Product $product): View
    {
        $this->assertOrderable($product);
        $product->load('prices', 'package', 'plan', 'category');

        return view('panel.store.product', [
            'product' => $product,
            'prices' => $product->priceMap(),
            'locations' => $product->locations(),
            'templates' => $product->type === Product::TYPE_VPS ? $this->templates($product) : collect(),
            'eggs' => $product->type === Product::TYPE_APP ? $product->eggs() : collect(),
            'balance' => (int) $request->user()->fresh()->wallet_balance,
            'remaining' => $product->remaining(),
        ]);
    }

    public function order(Request $request, Product $product): RedirectResponse
    {
        $this->assertOrderable($product);
        $user = $request->user();
        $prices = $product->priceMap();
        $locations = $product->locations();

        $rules = [
            'cycle' => ['required', Rule::in(array_keys($prices))],
            'location' => [$product->hypervisor_group_ids ? 'required' : 'nullable', 'integer', Rule::in($locations->pluck('id')->all())],
            'payment' => ['required', Rule::in(['wallet', 'invoice'])],
            'accept' => ['accepted'],
        ];
        if ($product->type === Product::TYPE_VPS) {
            $rules += [
                'template' => ['required', 'integer', Rule::in($this->templates($product)->pluck('id')->all())],
                'hostname' => ['required', 'string', 'max:253', 'regex:/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/i'],
                'ssh_key' => ['nullable', 'string', 'max:1000', new SshPublicKey],
            ];
        } else {
            $rules += [
                'egg' => ['required', 'integer', Rule::in($product->eggs()->pluck('id')->all())],
                'name' => ['required', 'string', 'max:60'],
            ];
        }
        $data = $request->validate($rules, [
            'hostname.regex' => __('Nazwa hosta musi być poprawną nazwą domenową, np. vps1.mojadomena.pl.'),
            'accept.accepted' => __('Zaakceptuj warunki zamówienia.'),
        ]);

        if ($product->type === Product::TYPE_VPS && ! ($user->isStaff() && $user->max_servers === null)) {
            $pending = BillingService::query()->where('user_id', $user->id)->where('status', BillingService::STATUS_PENDING)
                ->whereHas('product', fn ($q) => $q->where('type', Product::TYPE_VPS))->count();
            if ($user->servers()->count() + $pending >= $user->serverLimit()) {
                return back()->withInput()->withErrors(['cycle' => __('Osiągnięto limit :limit maszyn na koncie. ', ['limit' => $user->serverLimit()]).__('Napisz do nas, jeśli potrzebujesz go zwiększyć.')]);
            }
        }

        $config = ['location_id' => isset($data['location']) ? (int) $data['location'] : null];
        $config += $product->type === Product::TYPE_VPS
            ? ['template_id' => (int) $data['template'], 'hostname' => strtolower($data['hostname']), 'ssh_keys' => array_values(array_filter([trim((string) ($data['ssh_key'] ?? ''))]))]
            : ['egg_id' => (int) $data['egg'], 'name' => $data['name']];

        try {
            [$service, $invoice] = $this->services->checkout($user, $product, $data['cycle'], $config, $data['payment'] === 'wallet');
        } catch (InsufficientFunds|\DomainException $e) {
            return back()->withInput()->withErrors(['payment' => $e->getMessage()]);
        }

        if ($service->status === BillingService::STATUS_ACTIVE) {
            return redirect()->to($service->resourceUrl() ?? route('panel.billing'))
                ->with('status', __('Zamówienie opłacone — usługa jest tworzona.'));
        }
        if ($service->status === BillingService::STATUS_CANCELLED) {
            return redirect()->route('panel.billing')->withErrors(['service' => __('Nie udało się uruchomić usługi: :error. Pieniądze wróciły do portfela.', ['error' => $service->last_error])]);
        }

        return redirect()->route('panel.billing.invoice', $invoice)
            ->with('status', __('Zamówienie przyjęte. Opłać fakturę, a usługa uruchomi się automatycznie.'));
    }

    private function assertOrderable(Product $product): void
    {
        abort_unless(Billing::enabled() && $product->is_active && $product->category?->is_active && $product->deliverable(), 404);
    }

    private function templates(Product $product)
    {
        $package = $product->package;

        return OsTemplate::query()->where('is_active', true)->with('group')->orderBy('name')->get()
            ->filter(fn (OsTemplate $t) => $t->isSelfService() && ($package === null || $package->disk_gb >= $t->min_disk_gb)
                && ($t->group === null || $t->group->is_active))
            ->values();
    }
}
