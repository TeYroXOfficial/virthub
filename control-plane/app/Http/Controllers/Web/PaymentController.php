<?php

namespace App\Http\Controllers\Web;

use App\Domain\Billing\Gateways\GatewayException;
use App\Domain\Billing\Gateways\GatewayRegistry;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Płatność faktury przez bramkę: przekierowanie do dostawcy, powrót klienta i webhooki. */
class PaymentController extends Controller
{
    public function pay(Request $request, Invoice $invoice, string $gateway): RedirectResponse
    {
        abort_unless($invoice->user_id === $request->user()->id, 404);
        $driver = GatewayRegistry::get($gateway);
        abort_unless($driver !== null && $driver->available(), 404);
        if (! $invoice->isUnpaid() || $invoice->total <= 0) {
            return redirect()->route('panel.billing.invoice', $invoice)->withErrors(['payment' => __('Ta faktura nie czeka na zapłatę.')]);
        }

        try {
            return redirect()->away($driver->start($invoice));
        } catch (GatewayException $e) {
            return redirect()->route('panel.billing.invoice', $invoice)->withErrors(['payment' => $e->getMessage()]);
        } catch (Throwable $e) {
            Log::error('Bramka płatności: błąd przy starcie', ['gateway' => $gateway, 'invoice' => $invoice->id, 'error' => $e->getMessage()]);

            return redirect()->route('panel.billing.invoice', $invoice)->withErrors(['payment' => __('Nie udało się połączyć z bramką płatności. Spróbuj ponownie za chwilę.')]);
        }
    }

    /** Powrót klienta od dostawcy — sprawdzamy płatność po stronie serwera, nie ufamy adresowi. */
    public function return(Request $request, Invoice $invoice, string $gateway): RedirectResponse
    {
        abort_unless($invoice->user_id === $request->user()->id, 404);
        $driver = GatewayRegistry::get($gateway);
        abort_if($driver === null, 404);
        $back = redirect()->route('panel.billing.invoice', $invoice);

        try {
            $payment = $invoice->isUnpaid() ? $driver->complete($invoice, $request) : null;
        } catch (GatewayException $e) {
            return $back->withErrors(['payment' => $e->getMessage()]);
        } catch (Throwable $e) {
            Log::error('Bramka płatności: błąd przy powrocie', ['gateway' => $gateway, 'invoice' => $invoice->id, 'error' => $e->getMessage()]);

            return $back->withErrors(['payment' => __('Nie udało się potwierdzić płatności. Jeśli pieniądze zostały pobrane, faktura opłaci się automatycznie w ciągu kilku minut.')]);
        }

        if ($invoice->fresh()->status === Invoice::STATUS_PAID) {
            return $back->with('status', __('Dziękujemy — płatność przyjęta.'));
        }

        return $back->with('status', $payment === null
            ? __('Płatność jest przetwarzana. Faktura zmieni stan, gdy operator płatności ją potwierdzi.')
            : __('Płatność przyjęta.'));
    }

    public function webhook(Request $request, string $gateway): Response
    {
        $driver = GatewayRegistry::get($gateway);
        abort_if($driver === null, 404);

        return response('', $driver->webhook($request));
    }
}
