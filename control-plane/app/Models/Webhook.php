<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Adres, na który panel wysyła podpisane powiadomienia o zdarzeniach. */
class Webhook extends Model
{
    protected $fillable = ['name', 'url', 'secret', 'events', 'is_active', 'bearer_token', 'format', 'options'];

    protected $hidden = ['secret', 'bearer_token', 'options'];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'bearer_token' => 'encrypted',
            'options' => 'encrypted:array',
            'events' => 'array',
            'is_active' => 'boolean',
            'last_delivery_at' => 'datetime',
        ];
    }

    /** @return HasMany<WebhookDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    public function listensTo(string $event): bool
    {
        $events = $this->events ?? [];

        return in_array('*', $events, true) || in_array($event, $events, true);
    }
}
