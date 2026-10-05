<?php

namespace App\Http\Controllers\Web;

use App\Domain\Billing\Billing;
use App\Domain\Billing\Gateways\GatewayRegistry;
use App\Domain\Billing\InvoiceManager;
use App\Domain\Billing\Money;
use App\Domain\Billing\ServiceManager;
use App\Http\Controllers\Controller;
use App\Models\BillingService;
use App\Models\Invoice;
use App\Models\WalletTransaction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Rozliczenia klienta: usługi, faktury, portfel. */
class BillingController extends Controller
{
    public function __construct(
        private readonly InvoiceManager $invoices,
        private readonly ServiceManager $services,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();

        return view('panel.billing.index', [
            'balance' => (int) $user->fresh()->wallet_balance,
            'services' => BillingService::query()->where('user_id', $user->id)
                ->whereIn('status', [BillingService::STATUS_PENDING, ...BillingService::LIVE])->with('product')->latest()->get(),
            'unpaid' => Invoice::query()->where('user_id', $user->id)->where('status', Invoice::STATUS_UNPAID)->orderBy('due_at')->get(),
            'invoices' => Invoice::query()->where('user_id', $user->id)->latest('id')->paginate(15),
            'ended' => BillingService::query()->where('user_id', $user->id)
                ->whereIn('status', [BillingService::STATUS_TERMINATED, BillingService::STATUS_CANCELLED])->latest('updated_at')->limit(10)->get(),
        ]);
    }

    public function invoice(Request $request, Invoice $invoice): View
    {
        $this->own($request, $invoice->user_id);
        $invoice->load('items', 'payments');

        return view('panel.billing.invoice', [
            'invoice' => $invoice,
            'balance' => (int) $request->user()->fresh()->wallet_balance,
            'gateways' => GatewayRegistry::available(),
            'admin' => false,
        ]);
    }

    public function payWithWallet(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->own($request, $invoice->user_id);
        try {
            $this->invoices->payWithWallet($invoice, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['payment' => $e->getMessage()]);
        }

        return back()->with('status', __('Faktura opłacona z portfela.'));
    }

    public function wallet(Request $request): View
    {
        $user = $request->user();

        return view('panel.billing.wallet', [
            'balance' => (int) $user->fresh()->wallet_balance,
            'transactions' => WalletTransaction::query()->where('user_id', $user->id)->latest('id')->paginate(25),
            'min' => Billing::money('min_topup'),
            'max' => Billing::money('max_topup'),
        ]);
    }

    public function topup(Request $request): RedirectResponse
    {
        abort_unless(Billing::enabled(), 404);
        $min = Billing::money('min_topup');
        $max = Billing::money('max_topup');
        $data = $request->validate(['amount' => ['required', 'string', 'max:20']]);
        $amount = Money::valid($data['amount']) ? Money::roundCents(Money::parse($data['amount'])) : 0;
        if ($amount < max(100, $min) || ($max > 0 && $amount > $max)) {
            return back()->withInput()->withErrors(['amount' => __('Kwota doładowania musi być między :min a :max.', ['min' => Money::format(max(100, $min)), 'max' => Money::format($max)])]);
        }

        $invoice = $this->invoices->topup($request->user(), $amount);

        return redirect()->route('panel.billing.invoice', $invoice)->with('status', __('Wybierz sposób płatności, a środki trafią do portfela po jej zaksięgowaniu.'));
    }

    public function service(Request $request, BillingService $service): View
    {
        $this->own($request, $service->user_id);
        $service->load('product', 'invoiceItems.invoice');

        return view('panel.billing.service', ['service' => $service]);
    }

    public function cancel(Request $request, BillingService $service): RedirectResponse
    {
        $this->own($request, $service->user_id);
        $request->validate(['confirm' => ['accepted']]);
        try {
            $this->services->cancel($service, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['service' => $e->getMessage()]);
        }

        return back()->with('status', $service->fresh()->status === BillingService::STATUS_ACTIVE
            ? __('Usługa zostanie usunięta :date, z końcem opłaconego okresu.', ['date' => $service->next_due_at?->format('d.m.Y H:i')])
            : __('Usługa anulowana.'));
    }

    private function own(Request $request, int $ownerId): void
    {
        abort_unless($request->user()->id === $ownerId, 404);
    }
}
