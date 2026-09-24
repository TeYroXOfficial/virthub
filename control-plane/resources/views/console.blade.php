@extends('layouts.panel')

@section('title', 'Konsola — '.$server->hostname)

@if ($enabled && $kind === 'terminal')
    @push('head')
        <link rel="stylesheet" href="{{ asset('vendor/xterm/xterm.css') }}">
    @endpush
@endif

@section('content')
    <div class="page-header">
        <div>
            <h1>Konsola: {{ $server->hostname }}</h1>
            <p class="lede">
                @if ($kind === 'vnc')
                    Ekran maszyny (VNC) — działa nawet wtedy, gdy zepsuta konfiguracja sieci odcięła SSH.
                @else
                    Terminal kontenera — zaloguj się jako <code>root</code> hasłem z panelu.
                @endif
            </p>
        </div>
        <div class="actions">
            <a class="btn" href="{{ route('panel.servers.show', $server) }}"><x-icon name="arrow-left" :size="16"/> Wróć do maszyny</a>
        </div>
    </div>

    @if (! $enabled)
        <div class="alert alert-warning">
            <strong>Przekaźnik konsoli nie jest skonfigurowany na tej instalacji.</strong>
            <p>
                Brakuje <code>VIRTHUB_CONSOLE_SECRET</code> w pliku <code>.env</code> panelu.
                Instalator panelu ustawia go automatycznie razem z usługą <code>virthub-console</code> —
                uruchom aktualizację panelu jeszcze raz.
            </p>
        </div>
    @else
        <div class="console-frame" id="console-frame">
            <div class="console-toolbar">
                @if ($kind === 'vnc')
                    <button class="btn btn-sm" type="button" id="btn-cad" title="Wyślij Ctrl+Alt+Del">
                        <x-icon name="keyboard" :size="14"/> Ctrl+Alt+Del
                    </button>
                    <button class="btn btn-sm" type="button" id="btn-paste" title="Wpisz tekst ze schowka do maszyny">
                        <x-icon name="copy" :size="14"/> Wklej tekst
                    </button>
                @endif
                <button class="btn btn-sm" type="button" id="btn-full"><x-icon name="maximize" :size="14"/> Pełny ekran</button>
                <span class="console-status">
                    <span class="pill warning" id="console-state">łączenie…</span>
                    <button class="btn btn-sm" type="button" id="btn-reconnect" hidden>
                        <x-icon name="refresh" :size="14"/> Połącz ponownie
                    </button>
                </span>
            </div>
            <div class="console-screen">
                <div id="console-screen" class="{{ $kind === 'terminal' ? 'console-terminal' : '' }}"></div>
            </div>
        </div>
        <p class="hint" style="margin-top:10px">
            Sesja jest jednorazowa — po rozłączeniu albo odświeżeniu strony otwórz konsolę ponownie
            z widoku maszyny. Ruch idzie szyfrowanym tunelem przez panel; adres węzła nie trafia do przeglądarki.
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

                const rfb = new RFB(document.getElementById('console-screen'), window.VH_CONSOLE.url, {
                    credentials: { password: @js($vncPassword ?? '') },
                    wsProtocols: ['binary'],
                });
                rfb.scaleViewport = true;
                rfb.resizeSession = false;
                rfb.background = '#000';

                rfb.addEventListener('connect', () => {
                    vhConsoleState('połączono', 'ok');
                    rfb.focus();
                });
                rfb.addEventListener('disconnect', (e) => {
                    vhConsoleState(e.detail.clean ? 'rozłączono' : 'połączenie przerwane', 'critical');
                });
                rfb.addEventListener('securityfailure', () => vhConsoleState('odmowa dostępu VNC', 'critical'));

                document.getElementById('btn-cad').addEventListener('click', () => rfb.sendCtrlAltDel());

                // Wklejanie jako naciśnięcia klawiszy — schowek VNC nie działa
                // w konsoli przed zalogowaniem, a wpisanie hasła z menedżera tak.
                document.getElementById('btn-paste').addEventListener('click', async () => {
                    let text = '';
                    try { text = await navigator.clipboard.readText(); } catch (e) {}
                    if (!text) text = prompt('Tekst do wpisania w maszynie:') || '';
                    for (const ch of text) {
                        const code = ch.codePointAt(0);
                        const keysym = ch === '\n' ? 0xff0d : (code < 0x100 ? code : 0x01000000 + code);
                        rfb.sendKey(keysym);
                    }
                    rfb.focus();
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
                        vhConsoleState('połączono', 'ok');
                        sendSize();
                        term.focus();
                    };
                    ws.onmessage = (event) => term.write(new Uint8Array(event.data));
                    ws.onclose = (event) => {
                        vhConsoleState(event.reason || 'rozłączono', 'critical');
                        term.write('\r\n\x1b[90m— połączenie zamknięte —\x1b[0m\r\n');
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
