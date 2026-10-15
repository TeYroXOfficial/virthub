@extends('layouts.panel')

@section('title', __('Konsola — :hostname', ['hostname' => $server->hostname]))

@if ($enabled && $kind === 'terminal')
    @push('head')
        <link rel="stylesheet" href="{{ asset('vendor/xterm/xterm.css') }}">
    @endpush
@endif

@section('content')
    <div class="page-header">
        <div>
            <h1>{{ __('Konsola: :hostname', ['hostname' => $server->hostname]) }}</h1>
            <p class="lede">
                @if ($kind === 'vnc')
                    {{ __('Ekran maszyny (VNC) — działa nawet wtedy, gdy zepsuta konfiguracja sieci odcięła SSH.') }}
                @else
                    {{ __('Terminal kontenera — zaloguj się jako') }} <code>root</code> {{ __('hasłem z panelu.') }}
                @endif
            </p>
        </div>
        <div class="actions">
            <a class="btn" href="{{ route('panel.servers.show', $server) }}"><x-icon name="arrow-left" :size="16"/> {{ __('Wróć do maszyny') }}</a>
        </div>
    </div>

    @if (! $enabled)
        <div class="alert alert-warning">
            <strong>{{ __('Przekaźnik konsoli nie jest skonfigurowany na tej instalacji.') }}</strong>
            <p>
                {{ __('Brakuje') }} <code>VIRTHUB_CONSOLE_SECRET</code> {{ __('w pliku') }} <code>.env</code> {{ __('panelu. Instalator panelu ustawia go automatycznie razem z usługą') }} <code>virthub-console</code> {{ __('— uruchom aktualizację panelu jeszcze raz.') }}
            </p>
        </div>
    @else
        <div class="console-frame" id="console-frame">
            <div class="console-toolbar">
                @if ($kind === 'vnc')
                    <button class="btn btn-sm" type="button" id="btn-cad" title="{{ __('Wyślij Ctrl+Alt+Del') }}">
                        <x-icon name="keyboard" :size="14"/> {{ __('Ctrl+Alt+Del') }}
                    </button>
                    <button class="btn btn-sm" type="button" id="btn-keys" aria-pressed="false" title="{{ __('Klawisze specjalne i skróty') }}">
                        <x-icon name="keyboard" :size="14"/> {{ __('Klawisze') }}
                    </button>
                    <button class="btn btn-sm" type="button" id="btn-paste" title="{{ __('Wpisz tekst ze schowka do maszyny') }}">
                        <x-icon name="copy" :size="14"/> {{ __('Wpisz tekst') }}
                    </button>
                    <button class="btn btn-sm" type="button" id="btn-snippets" aria-pressed="false" title="{{ __('Zapisane fragmenty do wpisania') }}">
                        <x-icon name="list" :size="14"/> {{ __('Fragmenty') }}
                    </button>
                    <button class="btn btn-sm console-touch-only" type="button" id="btn-vk" title="{{ __('Klawiatura ekranowa') }}">
                        <x-icon name="keyboard" :size="14"/> {{ __('Klawiatura') }}
                    </button>
                    <span class="console-sep" aria-hidden="true"></span>
                    <label class="console-quality">
                        <span class="sr-only">{{ __('Jakość obrazu') }}</span>
                        <select id="console-quality" title="{{ __('Jakość obrazu') }}">
                            <option value="best">{{ __('Najlepsza jakość') }}</option>
                            <option value="balanced">{{ __('Zrównoważona') }}</option>
                            <option value="low">{{ __('Słabe łącze') }}</option>
                        </select>
                    </label>
                    <button class="btn btn-sm" type="button" id="btn-view-only" aria-pressed="false" title="{{ __('Tylko podgląd — klawiatura i mysz nie trafiają do maszyny') }}">
                        <x-icon name="eye" :size="14"/> {{ __('Tylko podgląd') }}
                    </button>
                    <button class="btn btn-sm" type="button" id="btn-shot" title="{{ __('Zapisz zrzut ekranu (PNG)') }}">
                        <x-icon name="camera" :size="14"/> {{ __('Zrzut') }}
                    </button>
                    <button class="btn btn-sm" type="button" id="btn-record" aria-pressed="false" title="{{ __('Nagraj ekran do pliku WebM (zapis w przeglądarce)') }}">
                        <x-icon name="record" :size="14"/> <span>{{ __('Nagrywaj') }}</span>
                    </button>
                    <button class="btn btn-sm" type="button" id="btn-pip" title="{{ __('Obraz w obrazie — ekran maszyny w małym oknie nad innymi') }}">
                        <x-icon name="pip" :size="14"/> {{ __('Obraz w obrazie') }}
                    </button>
                @endif
                <button class="btn btn-sm" type="button" id="btn-full"><x-icon name="maximize" :size="14"/> {{ __('Pełny ekran') }}</button>
                <span class="console-status">
                    <span class="pill warning" id="console-state">{{ __('łączenie…') }}</span>
                    <button class="btn btn-sm" type="button" id="btn-reconnect" hidden>
                        <x-icon name="refresh" :size="14"/> {{ __('Połącz ponownie') }}
                    </button>
                </span>
            </div>
            @if ($kind === 'vnc')
                <div class="console-panel" id="console-keys" hidden>
                    <div class="console-keys">
                        @foreach (['Ctrl', 'Alt', 'Shift', 'Super'] as $mod)
                            <button class="btn btn-sm" type="button" data-mod="{{ $mod }}" aria-pressed="false">{{ $mod === 'Super' ? 'Win' : $mod }}</button>
                        @endforeach
                        <span class="console-sep" aria-hidden="true"></span>
                        @foreach (['Escape' => 'Esc', 'Tab' => 'Tab', 'BackSpace' => '⌫', 'Return' => 'Enter', 'Delete' => 'Del', 'Insert' => 'Ins', 'Home' => 'Home', 'End' => 'End', 'PageUp' => 'PgUp', 'PageDown' => 'PgDn', 'Left' => '←', 'Up' => '↑', 'Down' => '↓', 'Right' => '→', 'Print' => 'PrtSc'] as $key => $label)
                            <button class="btn btn-sm" type="button" data-key="{{ $key }}">{{ $label }}</button>
                        @endforeach
                    </div>
                    <div class="console-keys">
                        @foreach (range(1, 12) as $n)
                            <button class="btn btn-sm" type="button" data-key="F{{ $n }}">F{{ $n }}</button>
                        @endforeach
                    </div>
                    <div class="console-keys">
                        <span class="muted">{{ __('Skróty:') }}</span>
                        @foreach (['Ctrl+Alt+F1', 'Ctrl+Alt+F2', 'Ctrl+Alt+F7', 'Alt+Tab', 'Alt+F4', 'Super+R', 'Ctrl+Shift+Escape', 'Ctrl+C', 'Ctrl+D', 'Ctrl+Z'] as $combo)
                            <button class="btn btn-sm" type="button" data-combo="{{ $combo }}">{{ str_replace(['Super', 'Escape'], ['Win', 'Esc'], $combo) }}</button>
                        @endforeach
                    </div>
                    <p class="hint">{{ __('Modyfikatory (Ctrl, Alt, Shift, Win) są przyklejane — kliknij modyfikator, potem klawisz.') }}</p>
                </div>
                <div class="console-panel" id="console-snippets" hidden>
                    <ul class="snippet-list" id="snippet-list"></ul>
                    <form id="snippet-form" class="snippet-form" autocomplete="off">
                        <input name="name" required maxlength="80" placeholder="{{ __('Nazwa, np. konfiguracja sieci') }}">
                        <textarea name="body" required maxlength="4000" rows="2" spellcheck="false" placeholder="{{ __('Tekst do wpisania (nowy wiersz = Enter)') }}"></textarea>
                        <button class="btn btn-sm" type="submit">{{ __('Zapisz fragment') }}</button>
                    </form>
                    <p class="hint">{{ __('Fragmenty są Twoje (widoczne w konsoli każdej maszyny) i szyfrowane w bazie. Kliknij nazwę, żeby wpisać tekst w maszynie.') }}</p>
                </div>
                <input id="console-vk" class="console-vk" type="text" autocapitalize="off" autocomplete="off" autocorrect="off" spellcheck="false" aria-label="{{ __('Klawiatura ekranowa') }}">
            @endif
            <div class="console-screen">
                <div id="console-screen" class="{{ $kind === 'terminal' ? 'console-terminal' : '' }}"></div>
            </div>
        </div>
        <p class="hint" style="margin-top:10px">
            {{ __('Sesja jest jednorazowa — po rozłączeniu albo odświeżeniu strony otwórz konsolę ponownie z widoku maszyny. Ruch idzie szyfrowanym tunelem przez panel; adres węzła nie trafia do przeglądarki.') }}
        </p>
    @endif
@endsection

@if ($enabled)
    @push('scripts')
        <script>
            window.VH_CONSOLE = {
                url: (location.protocol === 'https:' ? 'wss://' : 'ws://') + location.host + '/console-ws/{{ $session }}',
                reopen: @js(route('panel.servers.show', $server)),
            };

            (function () {
                const frame = document.getElementById('console-frame');
                document.getElementById('btn-full').addEventListener('click', () => {
                    document.fullscreenElement ? document.exitFullscreen() : frame.requestFullscreen?.();
                });
                document.getElementById('btn-reconnect').addEventListener('click', () => {
                    location.href = window.VH_CONSOLE.reopen;
                });
            })();

            window.vhConsoleState = function (text, tone) {
                const el = document.getElementById('console-state');
                el.textContent = text;
                el.className = 'pill ' + tone;
                document.getElementById('btn-reconnect').hidden = tone !== 'critical';
            };
        </script>

        @if ($kind === 'vnc')
            <script type="module">
                import RFB from @js(asset('vendor/novnc/core/rfb.js'));
                import { initVncTools } from @js(asset('js/vnc-console.js').'?v='.@filemtime(public_path('js/vnc-console.js')));

                const rfb = new RFB(document.getElementById('console-screen'), window.VH_CONSOLE.url, {
                    credentials: { password: @js($vncPassword ?? '') },
                    wsProtocols: ['binary'],
                });
                rfb.scaleViewport = true;
                rfb.resizeSession = false;
                rfb.background = '#000';

                rfb.addEventListener('connect', () => {
                    vhConsoleState(@js(__('połączono')), 'ok');
                    rfb.focus();
                });
                rfb.addEventListener('disconnect', (e) => {
                    vhConsoleState(e.detail.clean ? @js(__('rozłączono')) : @js(__('połączenie przerwane')), 'critical');
                });
                rfb.addEventListener('securityfailure', () => vhConsoleState(@js(__('odmowa dostępu VNC')), 'critical'));

                initVncTools(rfb, {
                    root: document.getElementById('console-frame'),
                    hostname: @js($server->hostname),
                    snippets: @js($snippets),
                    snippetUrl: @js(url('/console-snippets')),
                    csrf: document.querySelector('meta[name=csrf-token]').content,
                    t: {
                        typePrompt: @js(__('Tekst do wpisania w maszynie:')), typeTitle: @js(__('Wpisz tekst')), typeOk: @js(__('Wpisz')),
                        snippets: @js(__('Fragmenty')), noSnippets: @js(__('Brak zapisanych fragmentów.')),
                        typeSnippet: @js(__('Wpisz w maszynie')), deleteSnippet: @js(__('Usuń fragment')),
                        deleteConfirm: @js(__('Usunąć fragment „:name”?')), saveFailed: @js(__('Nie udało się zapisać fragmentu.')),
                        record: @js(__('Nagrywaj')), stopRecording: @js(__('Zatrzymaj nagrywanie')),
                    },
                });
            </script>
        @else
            <script src="{{ asset('vendor/xterm/xterm.js') }}"></script>
            <script src="{{ asset('vendor/xterm/addon-fit.js') }}"></script>
            <script>
                (function () {
                    const term = new Terminal({
                        cursorBlink: true,
                        fontFamily: '"JetBrains Mono", "Cascadia Code", Consolas, monospace',
                        fontSize: 14,
                        theme: { background: '#000000' },
                        scrollback: 5000,
                    });
                    const fit = new FitAddon.FitAddon();
                    term.loadAddon(fit);
                    term.open(document.getElementById('console-screen'));
                    fit.fit();

                    const ws = new WebSocket(window.VH_CONSOLE.url);
                    ws.binaryType = 'arraybuffer';
                    const encoder = new TextEncoder();

                    const sendSize = () => {
                        if (ws.readyState === WebSocket.OPEN) {
                            ws.send(JSON.stringify({ type: 'resize', cols: term.cols, rows: term.rows }));
                        }
                    };

                    ws.onopen = () => {
                        vhConsoleState(@js(__('połączono')), 'ok');
                        sendSize();
                        term.focus();
                    };
                    ws.onmessage = (event) => term.write(new Uint8Array(event.data));
                    ws.onclose = (event) => {
                        vhConsoleState(event.reason || @js(__('rozłączono')), 'critical');
                        term.write('\r\n\x1b[90m' + @js(__('— połączenie zamknięte —')) + '\x1b[0m\r\n');
                    };

                    term.onData((data) => {
                        if (ws.readyState === WebSocket.OPEN) ws.send(encoder.encode(data));
                    });
                    term.onResize(sendSize);
                    new ResizeObserver(() => fit.fit()).observe(document.getElementById('console-screen'));
                })();
            </script>
        @endif
    @endpush
@endif
