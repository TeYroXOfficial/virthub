@extends('layouts.panel')

@php
    $titles = ['ipv4' => __('Adresy IPv4'), 'nat' => __('Adresy IPv4 NAT'), 'ipv6' => __('Adresy IPv6')];
    $ledes = [
        'ipv4' => __('Publiczne adresy IPv4 ze wszystkich bloków — które są przypisane do jakiej maszyny, a które czekają wolne.'),
        'nat' => __('Prywatne adresy IPv4 za NAT-em węzłów, z blokami przekierowanych portów każdego adresu.'),
        'ipv6' => __('Adresy IPv6 przydzielone maszynom oraz dodane ręcznie. Wolne adresy IPv6 powstają przy zamówieniach, więc blok IPv6 nie kończy się w praktyce.'),
    ];
    $route = 'panel.admin.network.'.$kind;
@endphp

@section('title', $titles[$kind])

@section('content')
    <div class="page-header">
        <div>
            <h1>{{ $titles[$kind] }}</h1>
            <p class="lede">{{ $ledes[$kind] }}</p>
        </div>
        <div class="actions"><a class="btn" href="{{ route('panel.admin.ip-pools', ['version' => $kind === 'ipv6' ? 6 : 4]) }}">{{ __('Bloki IP') }}</a></div>
    </div>

    <div class="grid grid-4" style="margin-bottom:16px">
        @foreach (['all' => [__('Wszystkie'), 'neutral'], 'assigned' => [__('Przydzielone'), 'info'], 'free' => [__('Wolne'), 'ok'], 'reserved' => [__('Zarezerwowane'), 'neutral']] as $key => [$label, $tone])
            <a class="stat stat-link" href="{{ route($route, array_filter(['status' => $key === 'all' ? null : $key] + request()->only('pool', 'node', 'q'))) }}" @if ($status === $key) aria-current="true" @endif>
                <div class="stat-label"><i class="dot {{ $tone }}"></i> {{ $label }}</div>
                <div class="stat-value">{{ $counts[$key] }}</div>
            </a>
        @endforeach
    </div>

    <form method="GET" action="{{ route($route) }}" class="card filter-bar">
        <input type="hidden" name="status" value="{{ $status === 'all' ? '' : $status }}">
        <div class="field" style="margin:0">
            <label for="f-pool">{{ __('Blok') }}</label>
            <select id="f-pool" name="pool" onchange="this.form.submit()">
                <option value="">{{ __('wszystkie') }}</option>
                @foreach ($pools as $p)
                    <option value="{{ $p->id }}" @selected((int) request('pool') === $p->id)>{{ $p->name }} ({{ $p->cidr }})</option>
                @endforeach
            </select>
        </div>
        <div class="field" style="margin:0">
            <label for="f-node">{{ __('Węzeł') }}</label>
            <select id="f-node" name="node" onchange="this.form.submit()">
                <option value="">{{ __('wszystkie') }}</option>
                @foreach ($nodes as $n)
                    <option value="{{ $n->id }}" @selected((int) request('node') === $n->id)>{{ $n->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="field" style="margin:0; flex:1">
            <label for="f-q">{{ __('Szukaj') }}</label>
            <input id="f-q" name="q" type="search" value="{{ $term }}" placeholder="{{ __('adres, maszyna, e-mail klienta albo rDNS') }}">
        </div>
        <button class="btn" type="submit"><x-icon name="search" :size="15"/> {{ __('Filtruj') }}</button>
    </form>

    <div class="card flush">
        @include('panel.admin.network._address-table', ['showPool' => true, 'nat' => $kind === 'nat'])
    </div>
    {{ $addresses->links() }}
@endsection
