/*
 * Wykresy zużycia maszyny: podgląd na żywo i historia (godzina/doba/tydzień).
 *
 * Jeden wykres na jedną miarę — CPU, RAM, dysk, sieć — bez drugiej osi Y.
 * Dysk i sieć mają po dwie serie (odczyt/zapis, pobieranie/wysyłanie) w
 * kolorach --series-1 / --series-2 z panel.css; legenda pod wykresem pokazuje
 * wartości w punkcie kursora. Wymaga uPlot (public/vendor/uplot).
 *
 * Konfiguracja: element [data-server-metrics] z atrybutami data-live-url,
 * data-history-url i data-running ("1"/"0").
 */
(function () {
    'use strict';

    const root = document.querySelector('[data-server-metrics]');
    if (!root || typeof uPlot === 'undefined') return;

    const LIVE_INTERVAL = 3000;
    const LIVE_POINTS = 100; // 5 minut przy odczycie co 3 s

    const css = (name) => getComputedStyle(document.documentElement).getPropertyValue(name).trim();

    // --- formatowanie -----------------------------------------------------

    const fmtRate = (v) => {
        if (v == null) return '—';
        const units = ['B/s', 'KB/s', 'MB/s', 'GB/s'];
        let i = 0;
        while (Math.abs(v) >= 1024 && i < units.length - 1) { v /= 1024; i++; }
        return (v >= 100 || i === 0 ? v.toFixed(0) : v.toFixed(1)) + ' ' + units[i];
    };
    const fmtPct = (v) => (v == null ? '—' : v.toFixed(v >= 10 ? 0 : 1) + ' %');
    const fmtMem = (v) => (v == null ? '—' : v >= 1024 ? (v / 1024).toFixed(2) + ' GB' : Math.round(v) + ' MB');

    // Oś czasu po polsku: godziny przy krótkim zakresie, daty przy tygodniu.
    const pad = (n) => String(n).padStart(2, '0');
    const fmtTick = (secs, span) => {
        const d = new Date(secs * 1000);
        if (span <= 900) return pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
        if (span <= 172800) return pad(d.getHours()) + ':' + pad(d.getMinutes());
        return pad(d.getDate()) + '.' + pad(d.getMonth() + 1);
    };
    const fmtWhen = (secs) => new Date(secs * 1000).toLocaleString('pl-PL', {
        day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit',
    });

    // Bez najechania kursorem legenda pokazuje ostatnią znaną wartość, a nie „—”.
    const lastIndex = (u) => {
        for (let i = (u.data[0] || []).length - 1; i >= 0; i--) {
            if (u.data.slice(1).some((s) => s[i] != null)) return i;
        }
        return null;
    };
    const showLatest = (u) => {
        if (u.cursor.left == null || u.cursor.left < 0) {
            const idx = lastIndex(u);
            if (idx != null) u.setLegend({ idx });
        }
    };

    const METRICS = {
        cpu: { title: 'Procesor', fmt: fmtPct, max: 100, series: [['cpu_percent', 'CPU', '--series-1']] },
        ram: { title: 'Pamięć RAM', fmt: fmtMem, series: [['ram_used_mb', 'Użyte', '--series-1']] },
        disk: {
            title: 'Dysk I/O', fmt: fmtRate,
            series: [['disk_read_bps', 'Odczyt', '--series-1'], ['disk_write_bps', 'Zapis', '--series-2']],
        },
        net: {
            title: 'Sieć', fmt: fmtRate,
            series: [['net_rx_bps', 'Pobieranie', '--series-1'], ['net_tx_bps', 'Wysyłanie', '--series-2']],
        },
    };

    // --- fabryka wykresów ---------------------------------------------------

    function makeChart(el, key, opts) {
        const m = METRICS[key];
        const axisColor = css('--muted');
        const grid = { stroke: css('--grid'), width: 1 };
        const ticks = { show: false };
        const width = Math.max(260, el.clientWidth);

        const chart = new uPlot({
            width,
            height: 170,
            cursor: { y: false, points: { size: 8, width: 2, fill: css('--surface') } },
            select: { show: false },
            hooks: { setData: [showLatest], setCursor: [showLatest] },
            // Legenda pod wykresem jest też odczytem wartości w punkcie kursora.
            legend: { show: true, live: true },
            scales: {
                x: { time: true },
                y: {
                    range: (u, min, max) => {
                        const top = m.max ?? (opts.yMax?.() || Math.max(max || 0, 1) * 1.15);
                        return [0, m.max ?? top];
                    },
                },
            },
            axes: [
                {
                    stroke: axisColor, grid: { show: false }, ticks, font: '11px system-ui', space: 70,
                    values: (u, vals) => {
                        const span = u.scales.x.max - u.scales.x.min;
                        return vals.map((v) => fmtTick(v, span));
                    },
                },
                {
                    stroke: axisColor, grid, ticks, font: '11px system-ui', size: 64,
                    values: (u, vals) => vals.map((v) => m.fmt(v)),
                },
            ],
            series: [
                { label: 'Czas', value: (u, v) => (v == null ? '—' : fmtWhen(v)) },
                ...m.series.map(([field, label, color]) => ({
                    label,
                    stroke: css(color),
                    width: 2,
                    spanGaps: false,
                    points: { show: false },
                    value: (u, v) => m.fmt(v),
                })),
            ],
        }, [[], ...m.series.map(() => [])], el);

        new ResizeObserver(() => chart.setSize({ width: Math.max(260, el.clientWidth), height: 170 })).observe(el);
        return chart;
    }

    function buildGrid(container, prefix, opts = {}) {
        const charts = {};
        container.querySelectorAll('[data-chart]').forEach((el) => {
            const key = el.dataset.chart;
            charts[key] = makeChart(el, key, opts[key] || {});
        });
        return charts;
    }

    function setData(chart, key, series) {
        chart.setData([series.t, ...METRICS[key].series.map(([field]) => series[field])]);
    }

    // --- na żywo ------------------------------------------------------------

    const liveBox = root.querySelector('[data-live]');
    if (liveBox && root.dataset.running === '1') {
        const buffer = { t: [] };
        Object.values(METRICS).forEach((m) => m.series.forEach(([f]) => (buffer[f] = [])));
        let ramTotal = null;
        const charts = buildGrid(liveBox, 'live', { ram: { yMax: () => ramTotal } });
        const state = root.querySelector('[data-live-state]');
        const tiles = {
            cpu: liveBox.querySelector('[data-now="cpu"]'),
            ram: liveBox.querySelector('[data-now="ram"]'),
            disk: liveBox.querySelector('[data-now="disk"]'),
            net: liveBox.querySelector('[data-now="net"]'),
        };
        let timer = null;
        let failures = 0;

        const setState = (text, tone) => { state.textContent = text; state.className = 'pill ' + tone; };

        async function poll() {
            try {
                const res = await fetch(root.dataset.liveUrl, {
                    headers: { Accept: 'application/json' }, credentials: 'same-origin',
                });
                const data = await res.json();
                if (!res.ok) throw new Error(data.message || res.status);

                failures = 0;
                ramTotal = data.ram_total_mb || ramTotal;
                buffer.t.push(data.t);
                Object.keys(buffer).forEach((f) => { if (f !== 't') buffer[f].push(data[f] ?? null); });
                if (buffer.t.length > LIVE_POINTS) Object.values(buffer).forEach((a) => a.shift());

                Object.entries(charts).forEach(([key, chart]) => setData(chart, key, buffer));
                tiles.cpu.textContent = fmtPct(data.cpu_percent);
                tiles.ram.textContent = fmtMem(data.ram_used_mb) + (ramTotal ? ' / ' + fmtMem(ramTotal) : '');
                tiles.disk.textContent = '↓ ' + fmtRate(data.disk_read_bps) + '  ↑ ' + fmtRate(data.disk_write_bps);
                tiles.net.textContent = '↓ ' + fmtRate(data.net_rx_bps) + '  ↑ ' + fmtRate(data.net_tx_bps);
                setState('na żywo', 'ok');
            } catch (e) {
                failures++;
                setState(failures > 2 ? 'brak danych z węzła' : 'łączenie…', failures > 2 ? 'critical' : 'warning');
            }
        }

        const start = () => { if (!timer) { poll(); timer = setInterval(poll, LIVE_INTERVAL); } };
        const stop = () => { clearInterval(timer); timer = null; setState('wstrzymane', 'neutral'); };
        document.addEventListener('visibilitychange', () => (document.hidden ? stop() : start()));
        start();
    }

    // --- historia -----------------------------------------------------------

    const historyBox = root.querySelector('[data-history]');
    if (historyBox) {
        let ramTotal = null;
        const charts = buildGrid(historyBox, 'history', { ram: { yMax: () => ramTotal } });
        const buttons = historyBox.querySelectorAll('[data-range]');
        const empty = historyBox.querySelector('[data-history-empty]');
        const table = historyBox.querySelector('[data-history-table]');
        let current = null;

        function renderTable(series) {
            const body = table.querySelector('tbody');
            body.replaceChildren();
            for (let i = series.t.length - 1; i >= 0; i--) {
                if (series.cpu_percent[i] == null) continue;
                const row = document.createElement('tr');
                [
                    new Date(series.t[i] * 1000).toLocaleString('pl-PL'),
                    fmtPct(series.cpu_percent[i]),
                    fmtMem(series.ram_used_mb[i]),
                    fmtRate(series.disk_read_bps[i]),
                    fmtRate(series.disk_write_bps[i]),
                    fmtRate(series.net_rx_bps[i]),
                    fmtRate(series.net_tx_bps[i]),
                ].forEach((text, col) => {
                    const cell = document.createElement('td');
                    cell.textContent = text;
                    if (col > 0) cell.className = 'num';
                    row.appendChild(cell);
                });
                body.appendChild(row);
            }
        }

        async function load(range) {
            current = range;
            buttons.forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.range === range)));
            try {
                const res = await fetch(root.dataset.historyUrl + '?range=' + range, {
                    headers: { Accept: 'application/json' }, credentials: 'same-origin',
                });
                const data = await res.json();
                if (current !== range) return; // użytkownik zdążył przełączyć zakres
                ramTotal = data.ram_total_mb;
                Object.entries(charts).forEach(([key, chart]) => setData(chart, key, data.series));
                empty.hidden = data.samples > 0;
                renderTable(data.series);
                try { localStorage.setItem('vh-metrics-range', range); } catch (e) {}
            } catch (e) {
                empty.hidden = false;
                empty.textContent = 'Nie udało się pobrać historii.';
            }
        }

        buttons.forEach((b) => b.addEventListener('click', () => load(b.dataset.range)));
        let saved = 'day';
        try { saved = localStorage.getItem('vh-metrics-range') || 'day'; } catch (e) {}
        load(['hour', 'day', 'week'].includes(saved) ? saved : 'day');
        setInterval(() => { if (!document.hidden && current) load(current); }, 60000);
    }
})();
