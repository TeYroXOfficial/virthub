@extends('layouts.panel')

@section('title', __('Profile filtrowania'))

@section('content')
    <div class="page-header"><div><h1>{{ __('Profile filtrowania') }}</h1>
        <p class="lede">{{ __('Zasady ruchu maszyn ustalane przez administratora: anty-spoofing, blokada RA IPv6 i serwera DHCP, własne reguły. Profil wybierasz globalnie (domyślny) albo per maszyna.') }}</p></div></div>

    @if ($errors->has('rules')) <div class="alert alert-error"><div>{{ $errors->first('rules') }}</div></div> @endif

    @if ($profiles->isEmpty())
        <div class="card"><p class="muted" style="margin:0">{{ __('Brak profili — maszyny mają anty-spoofing bez dodatkowych blokad (zachowanie domyślne agenta).') }}</p></div>
    @endif

    @foreach ($profiles as $profile)
        <details class="card">
            <summary style="display:flex; gap:10px; align-items:center; flex-wrap:wrap">
                <strong>{{ $profile->name }}</strong>
                @if ($profile->is_default) <span class="pill ok">{{ __('domyślny') }}</span> @endif
                <span class="pill {{ $profile->anti_spoofing ? 'info' : 'warning' }} plain">{{ $profile->anti_spoofing ? __('anty-spoofing') : __('bez anty-spoofingu') }}</span>
                @if ($profile->block_router_advertisements) <span class="pill neutral plain">RA</span> @endif
                @if ($profile->block_dhcp_server) <span class="pill neutral plain">DHCP</span> @endif
                @if ($profile->rules) <span class="pill neutral plain">{{ trans_choice(':count reguła|:count reguły|:count reguł', count($profile->rules)) }}</span> @endif
                <span class="muted">{{ trans_choice(':count maszyna|:count maszyny|:count maszyn', $profile->servers_count + ($profile->is_default ? $defaultServers : 0)) }}</span>
            </summary>
            <form method="POST" action="{{ route('panel.admin.network.filters.update', $profile) }}" style="margin-top:14px">
                @csrf @method('PUT')
                @include('panel.admin.network._filter-fields', ['profile' => $profile, 'suffix' => '-'.$profile->id])
                <button class="btn btn-primary" type="submit">{{ __('Zapisz i zastosuj') }}</button>
            </form>
            <form method="POST" action="{{ route('panel.admin.network.filters.destroy', $profile) }}" style="margin-top:8px" data-confirm="{{ __('Usunąć profil? Jego maszyny przejdą na profil domyślny.') }}">
                @csrf @method('DELETE') <button class="btn btn-sm btn-danger" type="submit">{{ __('Usuń profil') }}</button>
            </form>
        </details>
    @endforeach

    <details class="card" @if ($profiles->isEmpty()) open @endif>
        <summary><strong>{{ __('Nowy profil') }}</strong></summary>
        <form method="POST" action="{{ route('panel.admin.network.filters.store') }}" style="margin-top:14px">
            @csrf
            @include('panel.admin.network._filter-fields', ['profile' => null, 'suffix' => ''])
            <button class="btn btn-primary" type="submit">{{ __('Dodaj profil') }}</button>
        </form>
    </details>
@endsection
