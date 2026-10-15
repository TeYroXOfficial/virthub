<?php

namespace App\Models;

use App\Domain\Billing\Addons;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Pakiet zasobów (samoobsługa): klient sam tworzy i usuwa maszyny dowolnej
 * wielkości, dopóki suma ich zasobów mieści się w budżecie pakietu.
 */
class ResourcePack extends Model
{
    protected $fillable = [
        'user_id', 'name', 'vcpu', 'ram_mb', 'disk_gb', 'max_servers', 'vps_package_id',
        'price_vcpu_hour', 'price_ram_gb_hour', 'price_disk_gb_hour', 'is_active', 'traffic_gb', 'price_ip_hour',
    ];

    protected $attributes = ['is_active' => true, 'max_servers' => 5];

    protected function casts(): array
    {
        return [
            'vcpu' => 'integer', 'ram_mb' => 'integer', 'disk_gb' => 'integer', 'max_servers' => 'integer',
            'price_vcpu_hour' => 'integer', 'price_ram_gb_hour' => 'integer', 'price_disk_gb_hour' => 'integer',
            'traffic_gb' => 'integer', 'price_ip_hour' => 'integer',
            'billed_until' => 'datetime', 'suspended_at' => 'datetime', 'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<VpsPackage, $this> */
    public function package(): BelongsTo
    {
        return $this->belongsTo(VpsPackage::class, 'vps_package_id');
    }

    /** @return HasMany<Server, $this> */
    public function servers(): HasMany
    {
        return $this->hasMany(Server::class);
    }

    /** @return array{vcpu:int, ram_mb:int, disk_gb:int, servers:int} */
    public function used(): array
    {
        $row = $this->servers()->selectRaw('COUNT(*) as n, COALESCE(SUM(vcpu),0) as vcpu, COALESCE(SUM(ram_mb),0) as ram, COALESCE(SUM(disk_gb),0) as disk')->first();

        return ['vcpu' => (int) $row->vcpu, 'ram_mb' => (int) $row->ram, 'disk_gb' => (int) $row->disk, 'servers' => (int) $row->n];
    }

    public function hasPricing(): bool
    {
        return (int) $this->price_vcpu_hour + (int) $this->price_ram_gb_hour + (int) $this->price_disk_gb_hour + (int) $this->price_ip_hour > 0;
    }

    /** Wspólny limit transferu dla wszystkich maszyn pakietu (zamiast limitów z pakietu VPS). */
    public function poolsTraffic(): bool
    {
        return (int) $this->traffic_gb > 0;
    }

    /** Koszt godziny maszyny pakietu razem z adresami ponad pakiet VPS. */
    public function serverHourlyCost(Server $server): int
    {
        $extraIps = (int) $this->price_ip_hour > 0 ? Addons::count($server)['ips'] : 0;

        return $this->hourlyCost($server->vcpu, $server->ram_mb, $server->disk_gb) + $extraIps * (int) $this->price_ip_hour;
    }

    /** Koszt godziny maszyny o podanych zasobach (grosze). */
    public function hourlyCost(int $vcpu, int $ramMb, int $diskGb): int
    {
        return (int) round($vcpu * (int) $this->price_vcpu_hour + $ramMb / 1024 * (int) $this->price_ram_gb_hour + $diskGb * (int) $this->price_disk_gb_hour);
    }
}
