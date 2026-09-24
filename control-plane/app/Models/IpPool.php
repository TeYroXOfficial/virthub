<?php

namespace App\Models;

use App\Domain\Network\IpMath;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IpPool extends Model
{
    use HasFactory;

    /** Adresy publiczne na mostku z kartą fizyczną węzła. */
    public const TYPE_PUBLIC = 'public';

    /** Adresy prywatne za NAT-em węzła, dostęp z zewnątrz przez porty. */
    public const TYPE_NAT = 'nat';

    public const TYPES = [self::TYPE_PUBLIC, self::TYPE_NAT];

    protected $fillable = [
        'hypervisor_id',
        'hypervisor_group_id',
        'name',
        'type',
        'cidr',
        'version',
        'gateway',
        'prefix',
        'range_from',
        'range_to',
        'nameservers',
        'nat_public_address',
        'nat_port_start',
        'nat_ports_per_server',
    ];

    protected $attributes = [
        'type' => self::TYPE_PUBLIC,
        'version' => 4,
    ];

    protected function casts(): array
    {
        return [
            'nameservers' => 'array',
            'version' => 'integer',
            'prefix' => 'integer',
            'next_offset' => 'integer',
            'nat_port_start' => 'integer',
            'nat_ports_per_server' => 'integer',
        ];
    }

    /** @return BelongsTo<Hypervisor, $this> */
    public function hypervisor(): BelongsTo
    {
        return $this->belongsTo(Hypervisor::class);
    }

    /** @return BelongsTo<HypervisorGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(HypervisorGroup::class, 'hypervisor_group_id');
    }

    /** @return HasMany<IpAddress, $this> */
    public function addresses(): HasMany
    {
        return $this->hasMany(IpAddress::class);
    }

    /**
     * Pule, z których może korzystać maszyna na danym węźle: własne węzła
     * oraz pule jego grupy.
     *
     * @param  Builder<IpPool>  $query
     */
    public function scopeUsableBy(Builder $query, Hypervisor $hypervisor): void
    {
        $query->where(function (Builder $scope) use ($hypervisor) {
            $scope->where('hypervisor_id', $hypervisor->id);

            if ($hypervisor->hypervisor_group_id !== null) {
                $scope->orWhere('hypervisor_group_id', $hypervisor->hypervisor_group_id);
            }
        });
    }

    public function isNat(): bool
    {
        return $this->type === self::TYPE_NAT;
    }

    public function isGroupPool(): bool
    {
        return $this->hypervisor_group_id !== null;
    }

    /**
     * Przestrzeń unikalności adresów puli — patrz migracja. Publiczne adresy
     * nie mogą się powtórzyć nigdzie, prywatne tylko w obrębie puli.
     */
    public function scopeKey(): string
    {
        return $this->isNat() ? "nat:{$this->id}" : 'public';
    }

    /** Czytelny opis zasięgu puli — dla panelu. */
    public function scopeLabel(): string
    {
        return $this->isGroupPool()
            ? 'grupa '.($this->group?->name ?? '?')
            : ($this->hypervisor?->name ?? '?');
    }

    public function typeLabel(): string
    {
        return $this->isNat() ? 'NAT' : 'publiczna';
    }

    /** Pierwszy adres, który wolno przydzielić. */
    public function firstAssignable(): string
    {
        return $this->range_from ?: IpMath::add(IpMath::networkAddress($this->cidr), 1);
    }

    /**
     * Ostatni adres, który wolno przydzielić. W IPv4 pomijamy adres
     * rozgłoszeniowy, w IPv6 takiego nie ma.
     */
    public function lastAssignable(): string
    {
        if ($this->range_to) {
            return $this->range_to;
        }

        $last = IpMath::lastAddress($this->cidr);

        return $this->version === 4 && $this->prefixBits() < 31
            ? IpMath::add(IpMath::networkAddress($this->cidr), IpMath::size($this->cidr) - 2)
            : $last;
    }

    public function prefixBits(): int
    {
        return IpMath::parseCidr($this->cidr)['bits'];
    }

    /**
     * Blok portów przekierowanych do adresu z puli NAT.
     *
     * Blok wynika z pozycji adresu w sieci (adres .5 → piąty blok), więc jest
     * stały przez całe życie adresu i nie wymaga osobnej tabeli przydziałów.
     *
     * @return array{from: int, to: int}|null
     */
    public function natPortsFor(string $address): ?array
    {
        if (! $this->isNat() || $this->version !== 4 || ! $this->nat_port_start || ! $this->nat_ports_per_server) {
            return null;
        }

        $offset = IpMath::offset(IpMath::networkAddress($this->cidr), $address);

        if ($offset === null) {
            return null;
        }

        $from = $this->nat_port_start + $offset * $this->nat_ports_per_server;
        $to = $from + $this->nat_ports_per_server - 1;

        return $to <= 65535 ? ['from' => $from, 'to' => $to] : null;
    }

    /**
     * Zakres portów, który pula może zająć na węźle — do wykrywania kolizji
     * między pulami NAT dzielącymi węzeł.
     *
     * @return array{from: int, to: int}|null
     */
    public function natPortSpan(): ?array
    {
        if (! $this->isNat() || $this->version !== 4 || ! $this->nat_port_start || ! $this->nat_ports_per_server) {
            return null;
        }

        $first = $this->natPortsFor($this->firstAssignable());
        $last = $this->natPortsFor($this->lastAssignable());

        return $first && $last ? ['from' => $first['from'], 'to' => $last['to']] : null;
    }

    /** @return list<string> */
    public function nameserverList(): array
    {
        if ($this->nameservers) {
            return $this->nameservers;
        }

        return $this->version === 6
            ? ['2606:4700:4700::1111', '2620:fe::fe']
            : ['1.1.1.1', '9.9.9.9'];
    }
}
