@extends('layouts.panel')

@section('title', $server->name)

@php
    $can = fn (string $c) => in_array($c, $capabilities, true);
    $ready = $server->remote_id !== null && ! $server->isSuspended() && ! in_array($server->status, ['pending', 'deleted'], true);
    $running = $server->status === 'running';
    $transitional = in_array($server->status, \App\Models\ExternalServer::TRANSITIONAL, true);
    $staff = auth()->user()->isStaff();
@endphp

@if ($transitional)
    @push('head') <meta http-equiv="refresh" content="10"> @endpush
@endif

@section('content')
    <div class="page-header">
        <div>
            <h1>{{ $server->name }}</h1>
            <div class="meta-line">
                <span class="pill {{ $server->statusTone() }}">{{ $server->statusLabel() }}</span>
                @if ($server->name !== $server->hostname) <span>{{ $server->hostname }}</span> @endif
                @if ($server->ipv4) <span class="sep">·</span> <span class="mono">{{ $server->ipv4 }}</span> @endif
                @if ($staff)
                    <span class="sep">·</span> <span>{{ __('dostawca :name', ['name' => $server->account?->name ?? '—']) }}</span>
                @endif
            </div>
        </div>
        <div class="actions">
            @if ($ready)
                @if ($can('console'))
                    @can('console', $server)
                        <form method="POST" action="{{ route('panel.cloud.console', $server) }}" target="_blank" style="margin:0">
                            @csrf
                            <button class="btn btn-primary" type="submit" @disabled(! $running)><x-icon name="monitor" :size="16"/> {{ __('Konsola') }}</button>
                        </form>
                    @endcan
                @endif
                @can('power', $server)
                    <div class="btn-group">
                        @foreach (['start' => [__('Start'), 'play', ! $running], 'reboot' => [__('Restart'), 'refresh', $running], 'stop' => [__('Stop'), 'stop', $running]] as $action => [$label, $icon, $enabled])
                            @if ($can($action))
                                <form method="POST" action="{{ route('panel.cloud.power', $server) }}" style="margin:0; display:inline">
                                    @csrf <input type="hidden" name="action" value="{{ $action }}">
                                    <button class="btn" type="submit" @disabled(! $enabled)><x-icon :name="$icon" :size="15"/> {{ $label }}</button>
                                </form>
                            @endif
                        @endforeach
                    </div>
                @endcan
            @endif
        </div>
    </div>

    @error('server') <div class="alert alert-error">{{ $message }}</div> @enderror
    @if ($server->isSuspended())
        <div class="alert alert-warning">{{ __('Serwer jest zawieszony. Sprawdź płatności albo skontaktuj się z obsługą.') }}</div>
    @elseif ($server->status === 'pending' || $server->status === 'building')
        <div class="alert alert-info">{{ __('Serwer jest tworzony — zwykle trwa to 1–3 minuty. Strona odświeży się sama.') }}</div>
    @elseif ($server->status === 'error')
        <div class="alert alert-error">{{ __('Nie udało się utworzyć serwera.') }} @if ($staff && $server->last_error) <span class="mono">{{ $server->last_error }}</span> @endif</div>
    @endif

    <div class="grid grid-2">
        <div class="card">
            <h3 class="card-title">{{ __('Dostęp') }}</h3>
            <dl class="kv">
                <dt>{{ __('Adres IPv4') }}</dt><dd class="mono">{{ $server->ipv4 ?? '—' }}</dd>
                @if ($server->ipv6) <dt>{{ __('Adres IPv6') }}</dt><dd class="mono">{{ $server->ipv6 }}</dd> @endif
                <dt>{{ __('Użytkownik') }}</dt><dd class="mono">root</dd>
                <dt>{{ __('Hasło') }}</dt>
                <dd>
                    @if ($server->password)
                        <span class="mono" data-secret="{{ $server->password }}">••••••••••••</span>
                        <button class="btn btn-sm btn-ghost" type="button" data-reveal>{{ __('Pokaż') }}</button>
                    @else
                        <span class="muted">{{ $ready ? __('ustawione przy instalacji (klucz SSH)') : '—' }}</span>
                    @endif
                </dd>
                <dt>{{ __('System') }}</dt><dd>{{ $server->image_name ?? $server->image }}</dd>
            </dl>
        </div>
        <div class="card">
            <h3 class="card-title">{{ __('Zasoby') }}</h3>
            <dl class="kv">
                <dt>{{ __('Procesor') }}</dt><dd>{{ $server->cpu ? $server->cpu.' vCPU' : '—' }}</dd>
                <dt>{{ __('Pamięć') }}</dt><dd>{{ $server->ram_mb ? round($server->ram_mb / 1024, 1).' GB' : '—' }}</dd>
                <dt>{{ __('Dysk') }}</dt><dd>{{ $server->disk_gb ? $server->disk_gb.' GB' : '—' }}</dd>
                <dt>{{ __('Utworzony') }}</dt><dd>{{ $server->created_at->format('Y-m-d H:i') }}</dd>
            </dl>
            <form method="POST" action="{{ route('panel.cloud.rename', $server) }}" class="filter-bar" style="margin-top:12px">
                @csrf @method('PUT')
                <div class="field" style="margin:0"><label for="c-name">{{ __('Nazwa w panelu') }}</label>
                    <input id="c-name" name="name" maxlength="100" value="{{ $server->name }}"></div>
                <button class="btn" type="submit">{{ __('Zapisz') }}</button>
            </form>
        </div>
    </div>

    @if ($ready)
        <div class="grid grid-2" style="margin-top:16px">
            @if ($can('rdns') && ($server->ipv4 || $server->ipv6))
                <div class="card">
                    <h3 class="card-title">{{ __('Odwrotny DNS (rDNS)') }}</h3>
                    <form method="POST" action="{{ route('panel.cloud.rdns', $server) }}">
                        @csrf
                        <div class="field"><label for="r-ip">{{ __('Adres') }}</label>
                            <select id="r-ip" name="ip">
                                @foreach (array_filter([$server->ipv4, $server->ipv6]) as $ip) <option value="{{ $ip }}">{{ $ip }}</option> @endforeach
                            </select></div>
                        <div class="field"><label for="r-host">{{ __('Nazwa hosta') }}</label>
                            <input id="r-host" name="hostname" maxlength="253" placeholder="{{ $server->hostname }}"></div>
                        <p class="hint">{{ __('Domena musi już wskazywać (rekord A/AAAA) na ten adres. Puste pole usuwa rekord.') }}</p>
                        <button class="btn" type="submit">{{ __('Zapisz') }}</button>
                    </form>
                </div>
            @endif
            @if ($can('reinstall') && $images)
                @can('rebuild', $server)
                    <div class="card">
                        <h3 class="card-title">{{ __('Reinstalacja systemu') }}</h3>
                        <p class="muted">{{ __('Wszystkie dane na dysku zostaną usunięte.') }}</p>
                        <form method="POST" action="{{ route('panel.cloud.reinstall', $server) }}">
                            @csrf
                            <div class="field"><label for="ri-image">{{ __('System operacyjny') }}</label>
                                <select id="ri-image" name="image">
                                    @foreach ($images as $image) <option value="{{ $image['id'] }}" @selected($image['id'] === $server->image)>{{ $image['name'] }}</option> @endforeach
                                </select></div>
                            <div class="field"><label for="ri-confirm">{{ __('Wpisz nazwę hosta, żeby potwierdzić') }}</label>
                                <input id="ri-confirm" name="confirm" autocomplete="off" placeholder="{{ $server->hostname }}" required></div>
                            @error('confirm') <p class="hint" style="color:var(--critical)">{{ $message }}</p> @enderror
                            <button class="btn btn-danger" type="submit">{{ __('Reinstaluj') }}</button>
                        </form>
                    </div>
                @endcan
            @endif
        </div>
    @endif
@endsection

@push('scripts')
    <script>
        document.querySelectorAll('[data-reveal]').forEach((btn) => btn.addEventListener('click', () => {
            const el = btn.previousElementSibling;
            const shown = el.dataset.shown === '1';
            el.textContent = shown ? '••••••••••••' : el.dataset.secret;
            el.dataset.shown = shown ? '0' : '1';
            btn.textContent = shown ? @json(__('Pokaż')) : @json(__('Ukryj'));
        }));
    </script>
@endpush
