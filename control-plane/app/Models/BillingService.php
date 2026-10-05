<?php

namespace App\Models;

use App\Domain\Billing\Cycle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Usługa rozliczana: zamówiony produkt, cykl i cena oraz powiązana maszyna
 * albo aplikacja. pending → active → suspended → terminated / cancelled.
 */
class BillingService extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_TERMINATED = 'terminated';

    public const STATUS_CANCELLED = 'cancelled';

    public const LIVE = [self::STATUS_ACTIVE, self::STATUS_SUSPENDED];

    protected $fillable = [
        'user_id', 'product_id', 'name', 'cycle', 'amount', 'status', 'server_id', 'app_server_id', 'config',
        'next_due_at', 'cancel_at_period_end', 'suspend_reason', 'suspended_at', 'terminated_at', 'last_error',
        'renews', 'metered',
    ];

    protected $attributes = ['status' => self::STATUS_PENDING, 'cancel_at_period_end' => false, 'renews' => true, 'metered' => false];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'config' => 'array',
            'next_due_at' => 'datetime',
            'cancel_at_period_end' => 'boolean',
            'suspended_at' => 'datetime',
            'terminated_at' => 'datetime',
            'renews' => 'boolean',
            'metered' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** @return BelongsTo<AppServer, $this> */
    public function appServer(): BelongsTo
    {
        return $this->belongsTo(AppServer::class);
    }

    /** @return HasMany<InvoiceItem, $this> */
    public function invoiceItems(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    /** Dopisek przy cenie: „/ mies.” albo „za 7 dni, jednorazowo”. */
    public function periodLabel(): string
    {
        return $this->renews ? Cycle::per($this->cycle) : __('za :period, jednorazowo', ['period' => Cycle::duration($this->cycle)]);
    }

    /** Pobierana z portfela co okres (godziny/dni, odnawiana). */
    public function metered(): bool
    {
        return (bool) ($this->attributes['metered'] ?? false);
    }

    public function isLive(): bool
    {
        return in_array($this->status, self::LIVE, true);
    }

    /** Link do maszyny/aplikacji w panelu klienta. */
    public function resourceUrl(): ?string
    {
        // Model, nie samo ID — aplikacje mają w adresie UUID, a nie numer.
        return match (true) {
            $this->server !== null => route('panel.servers.show', $this->server),
            $this->appServer !== null => route('panel.apps.show', $this->appServer),
            default => null,
        };
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => __('oczekuje na płatność'),
            self::STATUS_ACTIVE => ! $this->renews ? __('aktywna do :date', ['date' => $this->next_due_at?->format('d.m.Y H:i') ?? '—'])
                : ($this->cancel_at_period_end ? __('aktywna do końca okresu') : __('aktywna')),
            self::STATUS_SUSPENDED => __('zawieszona'),
            self::STATUS_TERMINATED => __('usunięta'),
            self::STATUS_CANCELLED => __('anulowana'),
            default => $this->status,
        };
    }

    public function statusTone(): string
    {
        return match ($this->status) {
            self::STATUS_ACTIVE => $this->cancel_at_period_end && $this->renews ? 'warning' : 'ok',
            self::STATUS_PENDING => 'info',
            self::STATUS_SUSPENDED => 'critical',
            default => 'neutral',
        };
    }
}
