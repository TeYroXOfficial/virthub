@extends('layouts.panel')

@section('title', $pool->name)

@php
    $free = $pool->addresses_count - $pool->assigned_count - $pool->reserved_count;
    $nsValue = $preset === 'custom' ? implode(', ', $pool->nameservers ?? []) : '';
@endphp

@section('content')
    <div class="page-header">
        <div>
            <a class="muted" href="{{ route('panel.admin.ip-pools') }}" style="font-size:13px">{{ __('← Bloki IP') }}</a>
            <h1>{{ $pool->name }}</h1>
            <div class="meta-line">
                <span class="pill neutral">{{ __('IPv:version', ['version' => $pool->version]) }}</span>
                <span class="pill {{ $pool->isNat() ? 'warning' : 'info' }}">{{ $pool->typeLabel() }}</span>
                <span class="mono">{{ $pool->cidr }}</span>
                <span class="sep">·</span>
                <span>{{ $pool->isGroupPool() ? __('grupa :name', ['name' => $pool->group?->name]) : ($pool->hypervisor?->name ?? '—') }}</span>
            </div>
        </div>
        <div class="actions">
            @if ($pool->assigned_count === 0)
                <form method="POST" action="{{ route('panel.admin.ip-pools.destroy', $pool) }}" style="margin:0"
                      data-confirm="{{ __('Usunąć blok :name razem z jego adresami?', ['name' => $pool->name]) }}">
                    @csrf @method('DELETE')
                    <button class="btn btn-danger" type="submit"><x-icon name="trash" :size="15"/> {{ __('Usuń blok') }}</button>
                </form>
            @endif
        </div>
    </div>

    <div class="grid grid-4" style="margin-bottom:16px">
        <div class="stat"><div class="stat-label">{{ __('Adresy w bloku') }}</div><div class="stat-value">{{ $pool->addresses_count }}</div>
            <div class="stat-sub">{{ $pool->version === 6 ? __('IPv6 dochodzą przy zamówieniach') : __('z podsieci :cidr', ['cidr' => $pool->cidr]) }}</div></div>
        <div class="stat"><div class="stat-label">{{ __('Przydzielone') }}</div><div class="stat-value">{{ $pool->assigned_count }}</div></div>
        <div class="stat"><div class="stat-label">{{ __('Wolne') }}</div><div class="stat-value">{{ $pool->version === 6 ? __('na żądanie') : $free }}</div>
            @if ($pool->version === 6 && $free > 0)<div class="stat-sub">{{ __(':count dodanych ręcznie czeka', ['count' => $free]) }}</div>@endif</div>
        <div class="stat"><div class="stat-label">{{ __('Zarezerwowane') }}</div><div class="stat-value">{{ $pool->reserved_count }}</div>
            <div class="stat-sub">{{ __('brama i adresy wyłączone ręcznie') }}</div></div>
    </div>

    @foreach ($pool->hostNetworkConflicts() as $c)
        <div class="alert alert-error">{{ __('Nachodzi na sieć węzła :node (:interface: :network) — SSH przez porty NAT nie zadziała. Utwórz pulę z inną podsiecią, np. 10.77.0.0/24.', $c) }}</div>
    @endforeach

    <div class="grid grid-2">
        <div class="card">
            <h3 class="card-title"><x-icon name="sliders" :size="16"/> {{ __('Ustawienia bloku') }}</h3>
            <form method="POST" action="{{ route('panel.admin.ip-pools.update', $pool) }}" data-pool-form>
                @csrf @method('PUT')
                <div class="field">
                    <label for="e-name">{{ __('Nazwa') }}</label>
                    <input id="e-name" name="name" type="text" maxlength="100" required value="{{ old('name', $pool->name) }}">
                </div>
                <div class="grid-compact">
                    <div class="field">
                        <label for="e-gw">{{ __('Brama') }}</label>
                        <input id="e-gw" name="gateway" type="text" required value="{{ old('gateway', $pool->gateway) }}">
                    </div>
                    <div class="field">
                        <label for="e-prefix">{{ __('Maska dla maszyny') }}</label>
                        <input id="e-prefix" name="prefix" type="text" required value="{{ old('prefix', $pool->prefix) }}">
                    </div>
                </div>
                @include('panel.admin.network._scope-fields', ['current' => $pool, 'idp' => 'e'])
                @include('panel.admin.network._dns-fields', ['idp' => 'e', 'preset' => $preset, 'nameservers' => $nsValue])
                @if ($pool->isNat())
                    <div class="field">
                        <label for="e-natpub">{{ __('Adres wyjścia NAT') }} <span class="muted">{{ __('(opcjonalnie)') }}</span></label>
                        <input id="e-natpub" name="nat_public_address" type="text" value="{{ old('nat_public_address', $pool->nat_public_address) }}">
                    </div>
                    @if ($span = $pool->natPortSpan())
                        <p class="hint">{{ __('Porty :from–:to, :per na adres od portu :start.', ['from' => $span['from'], 'to' => $span['to'], 'per' => $pool->nat_ports_per_server, 'start' => $pool->nat_port_start]) }}</p>
                    @endif
                @endif
                <p class="hint">{{ __('Podsieci i rodzaju bloku nie zmienisz — utwórz nowy blok. Wolne adresy przechodzą razem z blokiem na nowy węzeł; przydzielone zostają przy maszynach do czasu zwolnienia. Nowe resolwery maszyny dostaną przy reinstalacji.') }}</p>
                <button class="btn btn-primary" type="submit">{{ __('Zapisz') }}</button>
            </form>
        </div>

        <div class="card">
            <h3 class="card-title"><x-icon name="plus" :size="16"/> {{ __('Dodaj adresy') }}</h3>
            <form method="POST" action="{{ route('panel.admin.ip-pools.addresses', $pool) }}" data-address-form>
                @csrf
                <div class="field">
                    <label class="check-line"><input type="radio" name="mode" value="single" @checked(old('mode', 'single') === 'single')> {{ __('Pojedyncze adresy') }}</label>
                    <label class="check-line"><input type="radio" name="mode" value="range" @checked(old('mode') === 'range')> {{ __('Zakres od – do') }}</label>
                    @if ($pool->version === 4)
                        <label class="check-line"><input type="radio" name="mode" value="subnet" @checked(old('mode') === 'subnet')> {{ __('Cała podsieć') }}</label>
                    @endif
                </div>
                <div class="field" data-mode="single">
                    <label for="a-list">{{ __('Adresy') }} <span class="muted">{{ __('(jeden w linii albo po przecinku)') }}</span></label>
                    <textarea id="a-list" name="addresses" rows="4" class="mono" placeholder="{{ $pool->version === 4 ? '203.0.113.15' : '2001:db8::15' }}">{{ old('addresses') }}</textarea>
                </div>
                <div class="grid-compact" data-mode="range">
                    <div class="field"><label for="a-from">{{ __('Od') }}</label><input id="a-from" name="from" type="text" class="mono" value="{{ old('from') }}"></div>
                    <div class="field"><label for="a-to">{{ __('Do') }}</label><input id="a-to" name="to" type="text" class="mono" value="{{ old('to') }}"></div>
                </div>
                @if ($pool->version === 4)
                    <div class="field" data-mode="subnet">
                        <label for="a-cidr">{{ __('Podsieć') }}</label>
                        <input id="a-cidr" name="cidr" type="text" class="mono" value="{{ old('cidr', $pool->cidr) }}">
                        <div class="hint">{{ __('Cała podsieć bloku albo jej część, np. /28. Przy całej sieci bloku pomijamy adres sieci i rozgłoszeniowy.') }}</div>
                    </div>
                @endif
                <p class="hint">{{ __('Adresy muszą leżeć w podsieci :cidr. Te, które już są w panelu, pomijamy; brama zostaje zarezerwowana.', ['cidr' => $pool->cidr]) }}</p>
                <button class="btn btn-primary" type="submit">{{ __('Dodaj') }}</button>
            </form>
        </div>
    </div>

    <div class="card flush dash-section" style="margin-top:16px">
        <div class="dash-head">
            <h3 class="card-title"><x-icon name="list" :size="16"/> {{ __('Adresy') }}</h3>
            <form method="GET" class="filter-bar" style="gap:8px">
                <input type="hidden" name="status" value="{{ $status }}">
                <input name="q" type="search" value="{{ request('q') }}" placeholder="{{ __('Szukaj adresu') }}" aria-label="{{ __('Szukaj adresu') }}" style="width:200px">
            </form>
        </div>
        <nav class="tabs" style="padding:0 22px; margin:0" aria-label="{{ __('Stan adresu') }}">
            @foreach (['all' => __('Wszystkie'), 'assigned' => __('Przydzielone'), 'free' => __('Wolne'), 'reserved' => __('Zarezerwowane')] as $key => $label)
                <a href="{{ route('panel.admin.ip-pools.show', ['pool' => $pool, 'status' => $key]) }}" @if ($status === $key) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
        </nav>
        @include('panel.admin.network._address-table', ['showPool' => false, 'nat' => $pool->isNat()])
    </div>
    {{ $addresses->links() }}

    @include('panel.admin.network._form-script')
@endsection
