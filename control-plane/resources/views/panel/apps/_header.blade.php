{{-- Nagłówek i zakładki strony aplikacji. --}}
@php
    $icon = $app->icon();
    $tone = $app->statusTone();
@endphp
<div class="page-header">
    <div>
        <h1 class="os-title">
            <span class="os-badge app-badge"><x-icon :name="$icon" :size="20"/></span>
            {{ $app->name }}
        </h1>
        <div class="meta-line">
            <span class="pill {{ $tone }}" data-app-status>{{ $app->statusLabel() }}</span>
            <span>{{ $app->egg?->displayName() }}</span>
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
            @if ($tab !== 'console')
                <a class="btn btn-primary" href="{{ route('panel.apps.terminal', $app) }}">
                    <x-icon name="terminal" :size="16"/> {{ __('Konsola') }}
                </a>
            @endif
            <div class="btn-group" id="app-power">
                <button class="btn" data-power="start"><x-icon name="play" :size="15"/> {{ __('Start') }}</button>
                <button class="btn" data-power="restart"><x-icon name="refresh" :size="15"/> {{ __('Restart') }}</button>
                <button class="btn" data-power="stop"><x-icon name="stop" :size="15"/> {{ __('Stop') }}</button>
                <button class="btn btn-danger" data-power="kill" title="{{ __('Zabij proces — niezapisane dane przepadną') }}"><x-icon name="power" :size="15"/></button>
            </div>
            @can('operate', $app)
                <a class="btn" href="{{ route('panel.apps.settings', $app) }}#reinstall" title="{{ __('Uruchom instalację od nowa') }}">
                    <x-icon name="refresh" :size="15"/> {{ __('Reinstaluj') }}
                </a>
            @endcan
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
    @php
        $tabs = [
            'overview' => [__('Przegląd'), route('panel.apps.show', $app)],
            'console' => [__('Konsola'), route('panel.apps.terminal', $app)],
            'files' => [__('Pliki'), route('panel.apps.files', $app)],
        ];
        if ($app->isMinecraft()) {
            $tabs['modpacks'] = [__('Modpacki'), route('panel.apps.modpacks', $app)];
            $addonKind = (new \App\Domain\Apps\Content\ServerProfile($app))->addonKind();
            $tabs['addons'] = [$addonKind === 'mod' ? __('Mody') : __('Pluginy'), route('panel.apps.addons', $app)];
        }
        $tabs['startup'] = [__('Uruchamianie'), route('panel.apps.startup', $app)];
        $tabs['settings'] = [__('Ustawienia'), route('panel.apps.settings', $app)];
    @endphp
    @foreach ($tabs as $key => [$label, $url])
        <a href="{{ $url }}" @if ($tab === $key) aria-current="page" @endif>{{ $label }}</a>
    @endforeach
</nav>

@push('scripts')
    <script>
        document.querySelectorAll('[data-copy]').forEach((el) => el.addEventListener('click', () => {
            if (navigator.clipboard) navigator.clipboard.writeText(el.dataset.copy);
        }));
    </script>
    @if (($tab ?? null) !== 'console')
        {{-- Zasilanie i stan w nagłówku na każdej zakładce; konsola ładuje skrypt sama (po xterm). --}}
        @include('panel.apps._live-config', ['withConsole' => false])
        <script src="{{ asset('js/app-console.js') }}?v={{ @filemtime(public_path('js/app-console.js')) }}"></script>
    @endif
@endpush
