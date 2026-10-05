<?php

namespace App\Domain\Billing\Gateways;

use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Http\Request;

/** Bramka płatności z przekierowaniem: start → strona dostawcy → powrót / webhook. */
interface PaymentGateway
{
    public function key(): string;

    public function label(): string;

    /** Włączona i ma komplet danych. */
    public function available(): bool;

    /** Adres strony płatności dostawcy dla faktury. */
    public function start(Invoice $invoice): string;

    /** Powrót klienta — sprawdza płatność u dostawcy; null, gdy jeszcze nie opłacona. */
    public function complete(Invoice $invoice, Request $request): ?Payment;

    /** Webhook dostawcy — zwraca kod HTTP odpowiedzi. */
    public function webhook(Request $request): int;

    /** Test połączenia z kluczami — komunikat albo GatewayException. */
    public function test(): string;
}
