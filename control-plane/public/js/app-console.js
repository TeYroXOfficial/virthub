// Konsola aplikacji: logi (odpytywanie z kursorem), stan i zasilanie.
(function () {
    'use strict';
    const cfg = window.VH_APP;
    if (!cfg) return;
    const box = document.getElementById('app-console');
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    // eslint-disable-next-line no-control-regex
    const ANSI = /\x1b\[[0-9;?]*[A-Za-z]|\x1b\][^\x07]*\x07/g;
    let cursor = null;
    let source = null;
    let state = null;

    const post = (url, body) => fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
        body: JSON.stringify(body),
    });

    function append(lines, cls) {
        if (!box) return;
        const stick = box.scrollTop + box.clientHeight >= box.scrollHeight - 30;
        const frag = document.createDocumentFragment();
        for (const line of lines) {
            const div = document.createElement('div');
            div.textContent = String(line).replace(ANSI, '');
            if (cls) div.className = cls;
            frag.appendChild(div);
        }
        box.appendChild(frag);
        while (box.childNodes.length > 2000) box.removeChild(box.firstChild);
        if (stick) box.scrollTop = box.scrollHeight;
    }

    async function pollLogs() {
        try {
            const url = cfg.logs + (cursor ? ('?since=' + encodeURIComponent(cursor)) : '');
            const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
            const data = await res.json();
            if (data.source === 'install') {
                // Log instalacji przychodzi zawsze w całości (ostatnie linie).
                box.textContent = '';
                append([cfg.labels.install], 'sys');
                append(data.lines || []);
                cursor = null;
            } else {
                if (source === 'install' && data.source === 'console') box.textContent = '';
                append(data.lines || []);
                if (data.cursor) cursor = data.cursor;
            }
            source = data.source || source;
            if (data.error) append([data.error], 'err');
        } catch (e) { /* chwilowy brak połączenia — spróbujemy za chwilę */ }
        setTimeout(pollLogs, source === 'install' ? 3000 : 1500);
    }

    const fmtBytes = (b) => b == null ? '—' : (b >= 1073741824 ? (b / 1073741824).toFixed(2) + ' GB' : Math.round(b / 1048576) + ' MB');
    const set = (key, value) => { const el = document.querySelector('[data-stat="' + key + '"]'); if (el) el.textContent = value; };

    async function pollStatus() {
        try {
            const res = await fetch(cfg.status, { headers: { 'Accept': 'application/json' } });
            const data = await res.json();
            state = data.state;
            set('state', cfg.labels[state] || state || '—');
            set('cpu', state === 'running' && data.cpu_percent != null ? data.cpu_percent + '%' : '—');
            set('memory', state === 'running' ? fmtBytes(data.memory_bytes) : '—');
            set('disk', fmtBytes(data.disk_bytes));
            const pill = document.querySelector('[data-app-status]');
            if (pill && data.status_label) pill.textContent = data.status_label;
            document.querySelectorAll('#app-power [data-power]').forEach((b) => {
                const a = b.dataset.power;
                b.disabled = state === 'installing' || state === 'unreachable'
                    || (a === 'start' ? state === 'running' : state !== 'running');
            });
            if (cfg.installing && data.status !== 'installing') { location.reload(); return; }
        } catch (e) { /* następna próba za chwilę */ }
        setTimeout(pollStatus, 4000);
    }

    document.querySelectorAll('#app-power [data-power]').forEach((btn) => {
        btn.addEventListener('click', async () => {
            document.querySelectorAll('#app-power [data-power]').forEach((b) => { b.disabled = true; });
            append(['[VirtHub] ' + (btn.textContent.trim() || btn.title) + '…'], 'sys');
            const res = await post(cfg.power, { action: btn.dataset.power });
            if (!res.ok) {
                const data = await res.json().catch(() => ({}));
                append([data.message || ('HTTP ' + res.status)], 'err');
            }
            pollStatus();
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
            append(['> ' + command], 'sys');
            const res = await post(cfg.command, { command });
            if (!res.ok) {
                const data = await res.json().catch(() => ({}));
                append([data.message || ('HTTP ' + res.status)], 'err');
            }
        });
    }

    document.querySelectorAll('[data-copy]').forEach((el) => el.addEventListener('click', () => {
        if (navigator.clipboard) navigator.clipboard.writeText(el.dataset.copy);
    }));

    pollLogs();
    pollStatus();
})();
