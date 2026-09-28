// Konsola aplikacji: wyjście i statystyki na żywo przez WebSocket (przekaźnik
// konsoli panelu), a bez przekaźnika — odpytywanie logów z kursorem.
(function () {
    'use strict';
    const cfg = window.VH_APP;
    if (!cfg) return;
    const box = document.getElementById('app-console');
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const live = document.getElementById('app-live');
    let state = null;
    let ws = null;

    const post = (url, body) => fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
        body: JSON.stringify(body || {}),
    });

    // --- terminal (tylko wyjście — polecenia idą polem pod konsolą) ---------------
    const term = new Terminal({
        disableStdin: true,
        convertEol: true,
        cursorStyle: 'underline',
        cursorInactiveStyle: 'none',
        fontFamily: '"JetBrains Mono", "Cascadia Code", Consolas, monospace',
        fontSize: 13,
        scrollback: 5000,
        theme: { background: '#0b0f14', foreground: '#d5dde6' },
    });
    const fit = new FitAddon.FitAddon();
    term.loadAddon(fit);
    term.open(box);
    const refit = () => { try { fit.fit(); } catch (e) { /* ukryty element */ } };
    refit();
    window.addEventListener('resize', refit);
    document.addEventListener('fullscreenchange', () => setTimeout(refit, 50));

    const sys = (text) => term.writeln('\x1b[38;5;111m' + text + '\x1b[0m');
    const err = (text) => term.writeln('\x1b[38;5;210m' + text + '\x1b[0m');

    const full = document.getElementById('app-console-full');
    if (full) full.addEventListener('click', () => {
        const frame = box.closest('.app-console-frame');
        document.fullscreenElement ? document.exitFullscreen() : frame.requestFullscreen?.();
    });

    function mode(text, tone) {
        if (!live) return;
        live.textContent = text;
        live.className = 'pill ' + tone;
    }

    // --- stan i statystyki ---------------------------------------------------------
    const fmtBytes = (b) => b == null ? '—' : (b >= 1073741824 ? (b / 1073741824).toFixed(2) + ' GB' : Math.round(b / 1048576) + ' MB');
    const set = (key, value) => { const el = document.querySelector('[data-stat="' + key + '"]'); if (el) el.textContent = value; };

    function renderStatus(data) {
        const previous = state;
        state = data.state;
        set('state', cfg.labels[state] || state || '—');
        set('cpu', state === 'running' && data.cpu_percent != null ? data.cpu_percent + '%' : '—');
        set('memory', state === 'running' ? fmtBytes(data.memory_bytes) : '—');
        if (data.disk_bytes !== undefined) set('disk', fmtBytes(data.disk_bytes));
        const pill = document.querySelector('[data-app-status]');
        if (pill && data.status_label) pill.textContent = data.status_label;
        document.querySelectorAll('#app-power [data-power]').forEach((b) => {
            const a = b.dataset.power;
            b.disabled = state === 'installing' || state === 'unreachable'
                || (a === 'start' ? state === 'running' : state !== 'running');
        });
        // Koniec instalacji widziany na żywo — stan w panelu ustawia wynik zadania,
        // więc dopytujemy panel, zanim przeładujemy stronę.
        if (cfg.installing && previous === 'installing' && state !== 'installing') pollStatus(false);
    }

    async function pollStatus(repeat) {
        const liveOpen = ws && ws.readyState === WebSocket.OPEN;
        // Na żywo statystyki przychodzą gniazdem — panel pytamy tylko o koniec instalacji.
        if (repeat && liveOpen && !cfg.installing) { setTimeout(() => pollStatus(true), 8000); return; }
        try {
            const res = await fetch(cfg.status, { headers: { 'Accept': 'application/json' } });
            const data = await res.json();
            if (cfg.installing && data.status !== 'installing') { location.reload(); return; }
            if (!ws || ws.readyState !== WebSocket.OPEN) renderStatus(data);
        } catch (e) { /* następna próba za chwilę */ }
        if (repeat) setTimeout(() => pollStatus(true), ws ? 8000 : 4000);
    }

    // --- na żywo: WebSocket --------------------------------------------------------
    let retry = 0;
    let polling = false;

    async function connect() {
        mode(cfg.labels.connecting, 'warning');
        let res;
        try {
            res = await post(cfg.session);
        } catch (e) {
            return reconnect();
        }
        const data = await res.json().catch(() => ({}));
        if (!res.ok) {
            if (data.enabled === false) return startPolling();
            if (data.message) err(data.message);
            return reconnect();
        }

        const url = (location.protocol === 'https:' ? 'wss://' : 'ws://') + location.host + data.path;
        const socket = new WebSocket(url);
        socket.binaryType = 'arraybuffer';
        let opened = false;
        socket.onopen = () => {
            opened = true;
            retry = 0;
            ws = socket;
            term.reset(); // agent zaczyna od zaległych linii — bez dublowania po ponownym połączeniu
            mode(cfg.labels.live, 'ok');
        };
        socket.onmessage = (event) => {
            if (typeof event.data !== 'string') {
                term.write(new Uint8Array(event.data));
                return;
            }
            let msg;
            try { msg = JSON.parse(event.data); } catch (e) { return; }
            if (msg.type === 'status') renderStatus(msg);
            else if (msg.type === 'error') err(msg.message || '');
        };
        socket.onclose = () => {
            if (ws === socket) ws = null;
            if (opened) err(cfg.labels.lost);
            reconnect();
        };
    }

    function reconnect() {
        mode(cfg.labels.connecting, 'warning');
        retry = Math.min(retry + 1, 6);
        // Po kilku nieudanych próbach z rzędu dokładamy odpytywanie, żeby konsola nie stała pusta.
        if (retry >= 3 && !polling) startPolling(true);
        setTimeout(connect, Math.min(15000, 1000 * 2 ** (retry - 1)));
    }

    // --- bez przekaźnika: odpytywanie --------------------------------------------------
    let cursor = null;
    let source = null;

    function startPolling(temporary) {
        if (polling) return;
        polling = true;
        if (!temporary) mode(cfg.labels.polling, 'neutral');
        pollLogs();
    }

    async function pollLogs() {
        if (ws && ws.readyState === WebSocket.OPEN) { polling = false; cursor = null; source = null; return; }
        try {
            const url = cfg.logs + (cursor ? ('?since=' + encodeURIComponent(cursor)) : '');
            const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
            const data = await res.json();
            if (data.source === 'install') {
                // Log instalacji przychodzi zawsze w całości (ostatnie linie).
                term.reset();
                sys(cfg.labels.install);
                (data.lines || []).forEach((l) => term.writeln(l));
                cursor = null;
            } else {
                if (source === 'install' && data.source === 'console') term.reset();
                (data.lines || []).forEach((l) => term.writeln(l));
                if (data.cursor) cursor = data.cursor;
            }
            source = data.source || source;
            if (data.error) err(data.error);
        } catch (e) { /* chwilowy brak połączenia — spróbujemy za chwilę */ }
        setTimeout(pollLogs, source === 'install' ? 3000 : 1500);
    }

    // --- zasilanie i polecenia ------------------------------------------------------------
    document.querySelectorAll('#app-power [data-power]').forEach((btn) => {
        btn.addEventListener('click', async () => {
            document.querySelectorAll('#app-power [data-power]').forEach((b) => { b.disabled = true; });
            sys('[VirtHub] ' + (btn.textContent.trim() || btn.title) + '…');
            const res = await post(cfg.power, { action: btn.dataset.power });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) err(data.message || ('HTTP ' + res.status));
            else if (data.state && (!ws || ws.readyState !== WebSocket.OPEN)) renderStatus(data);
            if (!ws) pollStatus(false);
        });
    });

    const form = document.getElementById('app-command');
    if (form) {
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const input = form.elements.command;
            const command = input.value.trim();
            if (!command) return;
            input.value = '';
            sys('> ' + command);
            if (ws && ws.readyState === WebSocket.OPEN) {
                if (state && state !== 'running' && state !== 'starting') { err(cfg.labels.noCommand); return; }
                ws.send(JSON.stringify({ type: 'command', command }));
                return;
            }
            const res = await post(cfg.command, { command });
            if (!res.ok) {
                const data = await res.json().catch(() => ({}));
                err(data.message || ('HTTP ' + res.status));
            }
        });
    }

    if (cfg.session) connect(); else startPolling();
    pollStatus(true);
})();
