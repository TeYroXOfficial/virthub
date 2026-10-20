{{-- Zakładka „Sieć”: karta sieciowa, adresy IPv4/IPv6 (brama, DNS, rDNS), porty NAT i sieci prywatne. --}}
@php
    $isStaff = auth()->user()->isStaff();
    $primary = $server->primaryIp();
    $byVersion = $server->ipAddresses->groupBy(fn ($ip) => $ip->version === 6 ? 6 : 4);
    $speed = $server->port_speed_mbps ?? $server->package?->port_speed_mbps;
    $filter = $server->effectiveNetworkFilter();
@endphp

<div class="grid grid-2">
    <div class="card" id="nic">
        <h3 class="card-title"><x-icon name="network" :size="16"/> {{ __('Karta sieciowa') }}</h3>
        <dl class="kv">
            <dt>{{ __('Typ sieci') }}</dt>
            <dd>
                @if (! $primary)
                    —
                @elseif ($server->hasNatAddress())
                    <span class="pill warning">{{ __('NAT') }}</span>
                    <div class="hint">{{ __('Maszyna wychodzi w świat adresem węzła, a z zewnątrz jest osiągalna wyłącznie przez przekierowane porty.') }}</div>
                @else
                    <span class="pill ok">{{ __('Publiczny adres') }}</span>
                    @if ($primary->pool->isRouted()) <span class="pill neutral plain">{{ __('routowany') }}</span> @endif
                @endif
            </dd>
            <dt>{{ __('Interfejs') }}</dt>
            <dd>
                <span class="mono">eth0</span>
                <span class="muted">· {{ $server->isContainer() ? 'veth' : 'virtio-net' }}</span>
            </dd>
            @if ($mac = $server->ipAddresses->firstWhere('mac_address')?->mac_address)
                <dt>{{ __('MAC') }}</dt>
                <dd class="mono">{{ $mac }}
                    <div class="hint">{{ __('Wymagany przez dostawcę dla tego adresu — przy instalacji z ISO ustaw kartę z tym MAC-iem.') }}</div></dd>
            @endif
            <dt>{{ __('Prędkość portu') }}</dt>
            <dd class="num">{{ $speed ? __(':mbps Mbit/s', ['mbps' => $speed]) : __('bez limitu') }}</dd>
            @if ($isStaff && $primary?->pool->vlan_id)
                <dt>{{ __('VLAN') }}</dt><dd class="num">{{ $primary->pool->vlan_id }}</dd>
            @endif
            <dt>{{ __('Filtrowanie') }}</dt>
            <dd>
                {{ $filter?->name ?? __('brak') }}
                @if ($filter?->anti_spoofing) <div class="hint">{{ __('Ochrona przed podszywaniem się pod cudze adresy IP i MAC.') }}</div> @endif
            </dd>
            <dt>{{ __('Transfer') }}</dt>
            <dd>
                <span class="num">{{ \App\Domain\Metrics\Traffic::human($traffic['used']) }}</span>
                <span class="muted">{{ __('z :limit', ['limit' => $traffic['limit'] ? \App\Domain\Metrics\Traffic::human($traffic['limit']) : __('bez limitu')]) }}</span>
                · <a href="#traffic" class="muted">{{ __('szczegóły') }}</a>
            </dd>
        </dl>

        @if ($primary)
            @if ($primary->isNat())
                @if ($ports = $primary->natPorts())
                    @php $natHost = $primary->natEndpoint(); @endphp
                    <p class="hint" style="margin-top:12px">
                        @if ($ports['rdp'])
                            {{ __('Pulpit zdalny (RDP):') }} <code>{{ $natHost }}:{{ $ports['rdp'] }}</code><br>
                        @endif
                        @if ($ports['ssh'])
                            {{ __('SSH / SFTP:') }} <code>ssh -p {{ $ports['ssh'] }} {{ ($server->osFamily() === 'windows' ? 'Administrator' : 'root').'@'.$natHost }}</code>
                        @endif
                    </p>
                @endif
            @elseif ($server->osFamily() === 'windows')
                <p class="hint">{{ __('Pulpit zdalny (RDP):') }} <code>{{ $primary->address }}</code> · {{ __('użytkownik') }} <code>Administrator</code></p>
            @else
                <p class="hint">{{ __('Połączenie:') }} <code>ssh {{ 'root@'.$primary->address }}</code></p>
            @endif
        @endif
    </div>

    <div>
        @foreach ([4 => 'IPv4', 6 => 'IPv6'] as $version => $label)
            <div class="card" id="ipv{{ $version }}" @if ($version === 6) style="margin-top:16px" @endif>
                <h3 class="card-title"><x-icon name="network" :size="16"/> {{ $label }}</h3>
                @forelse ($byVersion->get($version, collect()) as $ip)
                    <dl class="kv" style="margin-bottom:12px">
                        <dt>{{ $ip->is_primary ? __('Główny') : __('Dodatkowy') }}</dt>
                        <dd class="mono">
                            @if ($ip->natPorts())
                                {{ $ip->publicAddress() }} <span class="pill warning">{{ __('NAT') }}</span>
                            @else
                                {{ $ip->address }}/{{ $ip->pool->prefix }}
                                @if ($ip->isNat()) <span class="pill warning">{{ __('NAT') }}</span> @endif
                            @endif
                        </dd>
                        @if ($ip->natPorts())
                            <dt>{{ __('Adres wewnętrzny') }}</dt><dd class="mono">{{ $ip->address }}/{{ $ip->pool->prefix }}</dd>
                        @endif
                        <dt>{{ __('Brama') }}</dt><dd class="mono">{{ $ip->pool->gateway }}</dd>
                        <dt>{{ __('DNS') }}</dt><dd class="mono">{{ implode(', ', $ip->pool->nameserverList()) }}</dd>
                        @if ($ports = $ip->natPorts())
                            <dt>{{ __('Porty') }}</dt>
                            <dd>
                                <span class="mono">{{ $ports['from'] }}–{{ $ports['to'] }}</span>
                                <a href="#ports" class="muted">{{ __('mapowanie') }}</a>
                            </dd>
                        @endif
                        @if ($ip->mac_address)
                            <dt>{{ __('MAC') }}</dt><dd class="mono">{{ $ip->mac_address }}</dd>
                        @endif
                        @unless ($ip->isNat())
                            <dt>{{ __('rDNS') }}</dt>
                            <dd>
                                <span class="mono">{{ $ip->rdns ?? '—' }}</span>
                                @can('operate', $server)
                                    <details class="form-block" style="margin-top:6px">
                                        <summary>{{ __('Zmień rDNS') }}</summary>
                                        <form method="POST" action="{{ route('panel.servers.rdns', [$server, $ip]) }}" style="margin-top:8px">
                                            @csrf @method('PUT')
                                            <div class="field" style="margin-bottom:8px">
                                                <input name="rdns" type="text" value="{{ $ip->rdns }}" placeholder="mail.example.com" aria-label="{{ __('rDNS') }}">
                                            </div>
                                            <button class="btn btn-sm btn-primary" type="submit">{{ __('Zapisz') }}</button>
                                            <div class="hint">{{ __('Nazwa musi wskazywać rekordem :type na :address. Puste pole usuwa rDNS.', ['type' => $ip->version === 6 ? 'AAAA' : 'A', 'address' => $ip->address]) }}</div>
                                        </form>
                                    </details>
                                @endcan
                            </dd>
                        @endunless
                        @if ($ip->version === 6 && ! $ip->isNat() && auth()->user()->can('firewall', $server))
                            <dt></dt>
                            <dd>
                                <details class="form-block">
                                    <summary>{{ __('Wybierz inny adres IPv6') }}</summary>
                                    <form method="POST" action="{{ route('panel.servers.ipv6', [$server, $ip]) }}" style="margin-top:8px">
                                        @csrf @method('PUT')
                                        <div class="field" style="margin-bottom:8px">
                                            <input name="address" type="text" placeholder="{{ $ip->address }}" aria-label="{{ __('Nowy adres IPv6') }}" required>
                                        </div>
                                        <button class="btn btn-sm btn-primary" type="submit">{{ __('Zmień adres') }}</button>
                                        <div class="hint">{{ __('Dowolny wolny adres z puli :cidr. Po zmianie ustaw adres w systemie (albo zreinstaluj go).', ['cidr' => $ip->pool->cidr]) }}</div>
                                    </form>
                                </details>
                            </dd>
                        @endif
                    </dl>
                @empty
                    <p class="muted" style="margin:0">{{ __('Brak adresu :version.', ['version' => $label]) }}</p>
                @endforelse
            </div>
        @endforeach
    </div>
</div>

@include('panel.servers._ports')
@include('panel.servers._private-networks')
