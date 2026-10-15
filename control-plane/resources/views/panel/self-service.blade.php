@extends('layouts.panel')

@section('title', __('Samoobsługa'))

@section('content')
    <div class="page-header"><div><h1>{{ __('Samoobsługa') }}</h1>
        <p class="lede">{{ __('Twórz i usuwaj maszyny dowolnej wielkości w ramach swojego pakietu zasobów.') }}</p></div></div>
    @error('pack') <div class="alert alert-error"><div>{{ $message }}</div></div> @enderror

    @foreach ($packs as $pack)
        @php
            $used = $pack->used();
            $bars = [
                ['vCPU', $used['vcpu'], $pack->vcpu, ''],
                ['RAM', $used['ram_mb'], $pack->ram_mb, ' MB'],
                [__('Dysk'), $used['disk_gb'], $pack->disk_gb, ' GB'],
                [__('Maszyny'), $used['servers'], $pack->max_servers, ''],
            ];
        @endphp
        <div class="card">
            <h3 class="card-title"><x-icon name="sliders" :size="16"/> {{ $pack->name }}
                @if ($pack->suspended_at) <span class="pill critical">{{ __('zawieszony — doładuj portfel') }}</span> @endif</h3>
            <div class="grid grid-4">
                @foreach ($bars as [$label, $u, $max, $unit])
                    <div>
                        <div class="muted">{{ $label }}</div>
                        <strong>{{ $u }}{{ $unit }}</strong> <span class="muted">/ {{ $max }}{{ $unit }}</span>
                        <div class="meter @if ($max && $u / $max > 0.9) hot @endif"><i style="width:{{ $max ? min(100, round($u * 100 / $max)) : 0 }}%"></i></div>
                    </div>
                @endforeach
            </div>
            @if ($billing && $pack->hasPricing())
                <p class="hint" style="margin-top:10px">{{ __('Stawki za godzinę: :cpu / vCPU, :ram / GB RAM, :disk / GB dysku — pobierane z portfela.', [
                    'cpu' => \App\Domain\Billing\Money::format((int) $pack->price_vcpu_hour),
                    'ram' => \App\Domain\Billing\Money::format((int) $pack->price_ram_gb_hour),
                    'disk' => \App\Domain\Billing\Money::format((int) $pack->price_disk_gb_hour),
                ]) }}@if ($pack->price_ip_hour > 0) {{ __('Dodatkowy adres IP: :ip / h.', ['ip' => \App\Domain\Billing\Money::format((int) $pack->price_ip_hour)]) }}@endif</p>
            @endif
            @if ($pack->poolsTraffic())
                <p class="hint">{{ __('Transfer wspólny dla wszystkich maszyn pakietu: :gb GB miesięcznie.', ['gb' => $pack->traffic_gb]) }}</p>
            @endif

            @if ($pack->servers->isNotEmpty())
                <div class="table-wrap" style="margin-top:12px">
                    <table>
                        <thead><tr><th>{{ __('Maszyna') }}</th><th>{{ __('Zasoby') }}</th><th>{{ __('System') }}</th><th>{{ __('Status') }}</th><th></th></tr></thead>
                        <tbody>
                        @foreach ($pack->servers as $server)
                            <tr>
                                <td><a href="{{ route('panel.servers.show', $server) }}"><strong>{{ $server->hostname }}</strong></a></td>
                                <td>{{ $server->vcpu }} vCPU · {{ $server->ram_mb }} MB · {{ $server->disk_gb }} GB</td>
                                <td>{{ $server->template?->name }}</td>
                                <td>{{ $server->state->label() }}</td>
                                <td style="text-align:right">
                                    <form method="POST" action="{{ route('panel.self-service.destroy', $server) }}" style="margin:0"
                                          data-confirm="{{ __('Usunąć :hostname razem z dyskiem? Tego nie da się cofnąć.', ['hostname' => $server->hostname]) }}">
                                        @csrf @method('DELETE') <button class="btn btn-sm btn-danger" type="submit" @disabled($server->state->isTransitioning())>{{ __('Usuń') }}</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            <details class="form-block" style="margin-top:14px" @if ($pack->servers->isEmpty()) open @endif>
                <summary>{{ __('Nowa maszyna') }}</summary>
                <form method="POST" action="{{ route('panel.self-service.store', $pack) }}">
                    @csrf
                    <div class="grid grid-3">
                        <div class="field"><label for="ss-host-{{ $pack->id }}">{{ __('Nazwa hosta') }}</label>
                            <input id="ss-host-{{ $pack->id }}" name="hostname" required maxlength="253" value="{{ old('hostname') }}" placeholder="vps1.example.com"></div>
                        <div class="field"><label for="ss-os-{{ $pack->id }}">{{ __('System') }}</label>
                            <select id="ss-os-{{ $pack->id }}" name="template" required>
                                @foreach ($templates as $template) <option value="{{ $template->id }}" @selected((int) old('template') === $template->id)>{{ $template->name }} {{ $template->version }}</option> @endforeach
                            </select></div>
                        <div class="field"><label for="ss-loc-{{ $pack->id }}">{{ __('Lokalizacja') }}</label>
                            <select id="ss-loc-{{ $pack->id }}" name="location">
                                <option value="">{{ __('dowolna') }}</option>
                                @foreach ($locations as $location) <option value="{{ $location->id }}">{{ $location->publicName() }}</option> @endforeach
                            </select></div>
                        <div class="field"><label for="ss-cpu-{{ $pack->id }}">vCPU</label>
                            <input id="ss-cpu-{{ $pack->id }}" name="vcpu" type="number" min="1" max="{{ max(1, $pack->vcpu - $used['vcpu']) }}" required value="{{ old('vcpu', 1) }}"></div>
                        <div class="field"><label for="ss-ram-{{ $pack->id }}">{{ __('RAM (MB)') }}</label>
                            <input id="ss-ram-{{ $pack->id }}" name="ram_mb" type="number" min="{{ \App\Domain\Provisioning\SelfService::MIN_RAM_MB }}" step="256" max="{{ max(512, $pack->ram_mb - $used['ram_mb']) }}" required value="{{ old('ram_mb', 1024) }}"></div>
                        <div class="field"><label for="ss-disk-{{ $pack->id }}">{{ __('Dysk (GB)') }}</label>
                            <input id="ss-disk-{{ $pack->id }}" name="disk_gb" type="number" min="1" max="{{ max(1, $pack->disk_gb - $used['disk_gb']) }}" required value="{{ old('disk_gb', 20) }}"></div>
                    </div>
                    <div class="field"><label for="ss-key-{{ $pack->id }}">{{ __('Klucz SSH (opcjonalnie)') }}</label>
                        <input id="ss-key-{{ $pack->id }}" name="ssh_key" maxlength="1000" value="{{ old('ssh_key') }}" placeholder="ssh-ed25519 AAAA…"></div>
                    @foreach (['hostname', 'template', 'vcpu', 'ram_mb', 'disk_gb', 'ssh_key', 'location'] as $field)
                        @error($field) <div class="hint" style="color:var(--critical)">{{ $message }}</div> @enderror
                    @endforeach
                    <button class="btn btn-primary" type="submit" @disabled($pack->suspended_at || $used['servers'] >= $pack->max_servers)>{{ __('Utwórz maszynę') }}</button>
                </form>
            </details>
        </div>
    @endforeach
@endsection
