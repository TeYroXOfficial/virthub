<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Historia przydziałów adresów IP: kto (maszyna, klient) miał adres i kiedy.
 * Dane klienta i maszyny są skopiowane — wpis przetrwa usunięcie maszyny,
 * co jest potrzebne przy zgłoszeniach nadużyć sprzed miesięcy.
 */
class IpAssignment extends Model
{
    public $timestamps = false;

    protected $fillable = ['ip_address_id', 'address', 'version', 'server_id', 'user_id', 'hostname', 'user_email', 'hypervisor_name', 'assigned_at', 'released_at'];

    protected function casts(): array
    {
        return ['assigned_at' => 'datetime', 'released_at' => 'datetime', 'version' => 'integer'];
    }

    /** Zmiana właściciela adresu — zamyka poprzedni wpis i otwiera nowy. */
    public static function track(IpAddress $address, ?int $previousServerId): void
    {
        if ($previousServerId !== null) {
            static::query()->where('ip_address_id', $address->id)->where('server_id', $previousServerId)->whereNull('released_at')
                ->update(['released_at' => now()]);
        }
        if ($address->server_id !== null) {
            $server = Server::withTrashed()->with(['user:id,email', 'hypervisor:id,name'])->find($address->server_id);
            static::create([
                'ip_address_id' => $address->id,
                'address' => $address->address,
                'version' => (int) $address->version,
                'server_id' => $address->server_id,
                'user_id' => $server?->user_id,
                'hostname' => $server?->hostname,
                'user_email' => $server?->user?->email,
                'hypervisor_name' => $server?->hypervisor?->name,
                'assigned_at' => $address->assigned_at ?? now(),
            ]);
        }
    }
}
