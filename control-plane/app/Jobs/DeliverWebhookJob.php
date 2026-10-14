<?php

namespace App\Jobs;

use App\Domain\Webhooks\Webhooks;
use App\Models\WebhookDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Pierwsza próba dostarczenia webhooka; ponowienia planuje Webhooks::retryDue(). */
class DeliverWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $deliveryId) {}

    public function handle(Webhooks $webhooks): void
    {
        $delivery = WebhookDelivery::query()->with('webhook')->find($this->deliveryId);
        if ($delivery !== null) {
            $webhooks->deliver($delivery);
        }
    }
}
