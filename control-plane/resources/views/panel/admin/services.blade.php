@extends('layouts.panel')

@section('title', __('Wszystkie usługi'))

@section('content')
    <h1>{{ __('Wszystkie usługi') }}</h1>
    <p class="lede">{{ __('Maszyny wirtualne, kontenery i aplikacje wszystkich klientów w jednym spisie.') }}</p>

    <div class="stats" style="display:flex; gap:12px; flex-wrap:wrap; margin-bottom:16px">
        @foreach ([['kvm', __('Maszyny KVM')], ['lxc', __('Kontenery')], ['app', __('Aplikacje')]] as [$key, $label])
            @continue($totals[$key] === null)
            <a class="card" href="{{ route('panel.admin.services', ['kind' => $key]) }}" style="flex:1; min-width:160px; margin:0; text-decoration:none">
                <div class="muted">{{ $label }}</div>
                <div style="font-size:26px; font-weight:700">{{ $totals[$key] }}</div>
            </a>
        @endforeach
    </div>

    <div class="card">
        <form method="GET" action="{{ route('panel.admin.services') }}">
            <div class="grid-compact">
                <div class="field">
                    <label for="s-q">{{ __('Szukaj') }}</label>
                    <input id="s-q" name="q" type="search" value="{{ request('q') }}" placeholder="{{ __('nazwa, adres IP, UUID albo klient') }}">
                </div>
                <div class="field">
                    <label for="s-kind">{{ __('Rodzaj') }}</label>
                    <select id="s-kind" name="kind">
                        <option value="">{{ __('wszystkie') }}</option>
                        @if ($canServers)
                            <option value="kvm" @selected(request('kind') === 'kvm')>{{ __('Maszyny KVM') }}</option>
                            <option value="lxc" @selected(request('kind') === 'lxc')>{{ __('Kontenery') }}</option>
                        @endif
                        @if ($canApps)
                            <option value="app" @selected(request('kind') === 'app')>{{ __('Aplikacje') }}</option>
                        @endif
                    </select>
                </div>
                <div class="field">
                    <label for="s-node">{{ __('Węzeł') }}</label>
                    <select id="s-node" name="node">
                        <option value="">{{ __('wszystkie') }}</option>
                        @foreach ($nodes as $node)
                            <option value="{{ $node->id }}" @selected((int) request('node') === $node->id)>{{ $node->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="s-status">{{ __('Stan') }}</label>
                    <select id="s-status" name="status">
                        <option value="">{{ __('wszystkie') }}</option>
                        <option value="problem" @selected(request('status') === 'problem')>{{ __('z problemem (błąd, zawieszone)') }}</option>
                        <option value="suspended" @selected(request('status') === 'suspended')>{{ __('zawieszone') }}</option>
                    </select>
                </div>
            </div>
            <div class="btn-row">
                <button class="btn btn-primary" type="submit">{{ __('Filtruj') }}</button>
                @if (request()->hasAny(['q', 'kind', 'node', 'status']))
                    <a class="btn" href="{{ route('panel.admin.services') }}">{{ __('Wyczyść') }}</a>
                @endif
            </div>
        </form>
    </div>

    <div class="card" style="padding:0">
        <div class="table-wrap">
            <table>
                <thead>
                <tr><th>{{ __('Usługa') }}</th><th>{{ __('Rodzaj') }}</th><th>{{ __('Klient') }}</th><th>{{ __('Stan') }}</th><th>{{ __('Adres') }}</th><th>{{ __('Węzeł') }}</th><th>{{ __('Zasoby') }}</th><th>{{ __('Utworzona') }}</th></tr>
                </thead>
                <tbody>
                @forelse ($items as $item)
                    @include('panel.admin._service-row')
                @empty
                    <tr><td colspan="8" class="muted" style="text-align:center; padding:24px">{{ __('Brak usług spełniających kryteria.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{ $page->links() }}
@endsection
