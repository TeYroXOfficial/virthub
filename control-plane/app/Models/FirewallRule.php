<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FirewallRule extends Model
{
    use HasFactory;

    public const MANAGED_BY_CUSTOMER = 'customer';

    public const MANAGED_BY_ADMIN = 'admin';

    protected $fillable = [
        'server_id',
        'managed_by',
        'enabled',
        'action',
        'direction',
        'protocol',
        'port_from',
        'port_to',
        'source',
        'comment',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'port_from' => 'integer',
            'port_to' => 'integer',
            'position' => 'integer',
        ];
    }

    public function isAdminRule(): bool
    {
        return $this->managed_by === self::MANAGED_BY_ADMIN;
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** Postać wysyłana do agenta — pola dokładnie jak w jego schemacie. */
    public function toAgentPayload(): array
    {
        return [
            'action' => $this->action,
            'direction' => $this->direction,
            'protocol' => $this->protocol,
            'port_from' => $this->port_from,
            'port_to' => $this->port_to,
            'source' => $this->source,
        ];
    }

    public function describe(): string
    {
        $protocol = match ($this->protocol) {
            'any' => 'cały ruch',
            'icmp' => 'ICMP (ping)',
            default => strtoupper($this->protocol),
        };

        $ports = match (true) {
            ! in_array($this->protocol, ['tcp', 'udp'], true) => null,
            $this->port_from && $this->port_to && $this->port_from !== $this->port_to
                => "porty {$this->port_from}–{$this->port_to}",
            (bool) $this->port_from => "port {$this->port_from}",
            default => 'wszystkie porty',
        };

        $peer = $this->direction === 'in'
            ? ($this->source ? "z {$this->source}" : 'z dowolnego adresu')
            : ($this->source ? "do {$this->source}" : 'do dowolnego adresu');

        $verb = $this->action === 'accept' ? 'Zezwól' : 'Zablokuj';
        $direction = $this->direction === 'in' ? 'przychodzący' : 'wychodzący';

        return "{$verb} ({$direction}): ".implode(', ', array_filter([$protocol, $ports, $peer]));
    }
}
