{{-- Nagłówek i zakładki strony aplikacji. --}}
@php
    $icon = $app->egg?->category === 'bot' ? 'bot' : 'gamepad';
    $tone = $app->isSuspended() || $app->status === \App\Models\AppServer::STATUS_INSTALL_FAILED ? 'critical' : ($app->isInstalling() ? 'warning' : 'neutral');
@endphp
<div class="page-header">
    <div>
        <h1 class="os-title">
            <span class="os-badge app-badge"><x-icon :name="$icon" :size="20"/></span>
            {{ $app->name }}
        </h1>
        <div class="meta-line">
            <span class="pill {{ $tone }}" data-app-status>{{ $app->statusLabel() }}</span>
            <span>{{ $app->egg?->name }}</span>
            @if ($address = $app->address())
                <span class="sep">·</span>
                <span class="mono copyable" title="{{ __('Kliknij, żeby skopiować') }}" data-copy="{{ $address }}">{{ $address }}</span>
            @endif
            @if ($app->hypervisor && auth()->user()->isStaff())
                <span class="sep">·</span> <span>{{ __('węzeł :name', ['name' => $app->hypervisor->name]) }}</span>
                <span class="sep">·</span> <span>{{ $app->user?->email }}</span>
            @endif
        </div>
    </div>
    @if ($app->acceptsCommands())
        <div class="actions">
            <div class="btn-group" id="app-power">
                <button class="btn" data-power="start"><x-icon name="play" :size="15"/> {{ __('Start') }}</button>
                <button class="btn" data-power="restart"><x-icon name="refresh" :size="15"/> {{ __('Restart') }}</button>
                <button class="btn" data-power="stop"><x-icon name="stop" :size="15"/> {{ __('Stop') }}</button>
                <button class="btn btn-danger" data-power="kill" title="{{ __('Zabij proces — niezapisane dane przepadną') }}"><x-icon name="power" :size="15"/></button>
            </div>
        </div>
    @endif
</div>

@if ($app->isSuspended())
    <div class="alert alert-error">{{ __('Aplikacja jest zawieszona: :reason', ['reason' => $app->suspension_reason]) }}</div>
@elseif ($app->status === \App\Models\AppServer::STATUS_INSTALL_FAILED)
    <div class="alert alert-error">
        <strong>{{ __('Instalacja nie powiodła się.') }}</strong>
        <pre class="install-error">{{ $app->status_message }}</pre>
        {{ __('Popraw zmienne w zakładce Uruchamianie i uruchom reinstalację w Ustawieniach.') }}
    </div>
@endif

<nav class="tabs" aria-label="{{ __('Sekcje aplikacji') }}">
    @foreach ([
        'console' => [__('Konsola'), route('panel.apps.show', $app)],
        'files' => [__('Pliki'), route('panel.apps.files', $app)],
        'startup' => [__('Uruchamianie'), route('panel.apps.startup', $app)],
        'settings' => [__('Ustawienia'), route('panel.apps.settings', $app)],
    ] as $key => [$label, $url])
        <a href="{{ $url }}" @if ($tab === $key) aria-current="page" @endif>{{ $label }}</a>
    @endforeach
</nav>
