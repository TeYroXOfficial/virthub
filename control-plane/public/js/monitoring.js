/*
 * Infrastruktura → Monitorowanie: co kilka sekund pobiera raport węzła
 * (przez panel, który dokłada nazwy usług) i odświeża kafelki, tabele
 * oraz krótkie wykresy z ostatnich kilku minut.
 */
(function () {
    'use strict';

    const root = document.getElementById('monitoring');
    if (!root) return;

    const L = window.VH_MONITOR || {};
    const INTERVAL = 5000;
    const HISTORY = 60;
    const history = { cpu: [], steal: [], mem: [], temp: [] };
    const statusPill = document.getElementById('mon-status');
    const errorBox = document.getElementById('mon-error');
    let services = [];
    let sort = { key: 'cpu_pct', dir: -1 };
    let timer = null;

    // --- formatowanie -------------------------------------------------------

    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const bytes = (n) => {
        if (n === null || n === undefined) return '—';
        const units = ['B', 'KB', 'MB', 'GB', 'TB'];
        let i = 0;
        let v = Number(n);
        while (v >= 1024 && i < units.length - 1) { v /= 1024; i++; }
        return (v >= 100 || i === 0 ? v.toFixed(0) : v.toFixed(1)) + ' ' + units[i];
    };
    const rate = (n) => (n === null || n === undefined ? '—' : bytes(n) + '/s');
    const bits = (n) => {
        if (n === null || n === undefined) return '—';
        const units = ['b/s', 'Kb/s', 'Mb/s', 'Gb/s'];
        let v = Number(n) * 8;
        let i = 0;
        while (v >= 1000 && i < units.length - 1) { v /= 1000; i++; }
        return v.toFixed(v >= 100 || i === 0 ? 0 : 1) + ' ' + units[i];
    };
    const pct = (n) => (n === null || n === undefined ? '—' : Number(n).toFixed(1).replace(/\.0$/, '') + '%');
    const tone = (v, warn = 75, crit = 90) => (v >= crit ? 'critical' : v >= warn ? 'hot' : '');
    const meter = (v, warn, crit) => v === null || v === undefined ? ''
        : `<div class="meter ${tone(v, warn, crit)}"><i style="width:${Math.min(100, Math.max(0, v))}%"></i></div>`;
    const set = (key, html) => root.querySelectorAll(`[data-v="${key}"]`).forEach((el) => { el.innerHTML = html; });
    const uptime = (s) => {
        const d = Math.floor(s / 86400);
        const h = Math.floor((s % 86400) / 3600);
        const m = Math.floor((s % 3600) / 60);
        return (d ? `${d} ${L.days} ` : '') + `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`;
    };

    function spark(name, value, max) {
        const series = history[name];
        series.push(value === null || value === undefined ? null : Number(value));
        if (series.length > HISTORY) series.shift();
        const svg = root.querySelector(`[data-spark="${name}"]`);
        if (!svg) return;
        const top = max || Math.max(1, ...series.filter((v) => v !== null));
        const step = 120 / (HISTORY - 1);
        const offset = HISTORY - series.length;
        const points = series.map((v, i) => v === null ? null : `${((offset + i) * step).toFixed(1)},${(27 - (v / top) * 25).toFixed(1)}`).filter(Boolean);
        svg.innerHTML = points.length > 1
            ? `<polyline points="${points.join(' ')}" fill="none" stroke="var(--accent)" stroke-width="1.5" vector-effect="non-scaling-stroke"/>`
            : '';
    }

    function owner(o) {
        if (!o) return `<span class="muted">${esc(L.system)}</span>`;
        const kind = `<span class="pill neutral plain">${esc(L.kinds[o.kind] || o.kind)}</span> `;
        const name = o.url ? `<a href="${esc(o.url)}">${esc(o.name)}</a>` : `<span class="muted">${esc(o.name)} (${esc(L.unknown)})</span>`;
        return kind + name;
    }

    // --- sekcje --------------------------------------------------------------

    function renderTiles(r) {
        const cpu = r.cpu || {};
        const mem = r.memory || {};
        const memPct = mem.total ? (mem.used / mem.total) * 100 : null;
        const cpuTemps = (r.temperatures || []).filter((t) => t.category === 'cpu').map((t) => t.celsius);
        const cpuTemp = cpuTemps.length ? Math.max(...cpuTemps) : null;

        set('cpu.usage', pct(cpu.usage));
        set('cpu.detail', `${cpu.cores || '?'} ${esc(L.cores)} · ${esc(L.load)} ${(r.host?.load || []).map((v) => v.toFixed(2)).join(' / ')}`);
        set('cpu.steal', pct(cpu.steal));
        set('cpu.steal_detail', `iowait ${pct(cpu.iowait)}`);
        set('mem.pct', pct(memPct));
        set('mem.detail', `${bytes(mem.used)} / ${bytes(mem.total)}`);
        set('temp.cpu', cpuTemp === null ? '—' : `${cpuTemp.toFixed(0)}°C`);
        set('host.load', `${esc(L.uptime)}: ${uptime(r.host?.uptime || 0)}`);

        spark('cpu', cpu.usage, 100);
        spark('steal', cpu.steal);
        spark('mem', memPct, 100);
        spark('temp', cpuTemp);
    }

    function renderCpu(r) {
        const c = r.cpu || {};
        const seg = (v, color) => v ? `<i style="width:${v}%;background:${color}" title="${pct(v)}"></i>` : '';
        set('cpu.stack', seg(c.user, 'var(--series-1)') + seg(c.system, 'var(--series-2)') + seg(c.iowait, 'var(--warn)') + seg(c.steal, 'var(--critical)'));
        set('cpu.cores', (c.per_core || []).map((v, i) =>
            `<div class="core" title="CPU ${i}: ${pct(v)}"><span>${i}</span><div class="meter ${tone(v)}"><i style="width:${v}%"></i></div><b>${Math.round(v)}%</b></div>`).join(''));
        const h = r.host || {};
        set('host.cpu', esc(h.hostname || ''));
        set('host.kv', [
            [L.model, h.cpu_model || '—'],
            [L.clock, h.cpu_mhz ? `${h.cpu_mhz} MHz` : '—'],
            [L.kernel, h.kernel || '—'],
            [L.uptime, uptime(h.uptime || 0)],
        ].map(([k, v]) => `<dt>${esc(k)}</dt><dd>${esc(v)}</dd>`).join(''));
    }

    function renderMemory(r) {
        const m = r.memory || {};
        const swapPct = m.swap_total ? (m.swap_used / m.swap_total) * 100 : null;
        set('mem.kv', [
            [L.used, `${bytes(m.used)} / ${bytes(m.total)}`, m.total ? (m.used / m.total) * 100 : null],
            [L.available, bytes(m.available), null],
            [L.cached, bytes(m.cached), null],
            [L.dirty, bytes(m.dirty), null],
            [L.swap, m.swap_total ? `${bytes(m.swap_used)} / ${bytes(m.swap_total)}` : L.none, swapPct],
        ].map(([k, v, p]) => `<dt>${esc(k)}</dt><dd>${esc(v)}${p !== null ? meter(p, 80, 95) : ''}</dd>`).join(''));

        const p = r.pressure || {};
        set('pressure', [['cpu', L.cpu], ['memory', L.memory], ['io', L.io]].map(([key, label]) => {
            const v = p[key]?.some;
            return `<div class="psi-row"><span>${esc(label)}</span><span class="num">${pct(v)}</span>${meter(v ?? null, 10, 30)}</div>`;
        }).join(''));
    }

    function renderTemps(r) {
        const temps = r.temperatures || [];
        set('temps', temps.length ? temps.map((t) => {
            const hot = t.critical ? t.celsius >= t.critical - 10 : t.celsius >= (t.category === 'disk' ? 55 : 85);
            return `<tr><td>${esc(t.label)} <span class="hint mono">${esc(t.sensor)}</span></td>
                <td>${esc(L.categories[t.category] || t.category)}</td>
                <td class="mono">${esc(t.device || '—')}</td>
                <td class="num"><span class="pill ${hot ? 'critical' : 'ok'} plain">${t.celsius.toFixed(1)}°C</span></td>
                <td class="num muted">${t.critical ? t.critical.toFixed(0) + '°C' : '—'}</td></tr>`;
        }).join('') : `<tr><td colspan="5" class="muted">${esc(L.noTemps)}</td></tr>`);
    }

    function renderDisks(r) {
        set('disks', (r.disks || []).map((d) => `<tr>
            <td><strong class="mono">${esc(d.name)}</strong> <span class="pill neutral plain">${d.rotational ? L.hdd : L.ssd}</span>
                ${d.model ? `<div class="hint">${esc(d.model)}</div>` : ''}</td>
            <td class="num">${bytes(d.size)}</td>
            <td class="num">${rate(d.read_bps)}</td>
            <td class="num">${rate(d.write_bps)}</td>
            <td class="num">${d.read_iops ?? '—'} / ${d.write_iops ?? '—'}</td>
            <td class="metric-cell"><span class="num">${pct(d.util)}</span>${meter(d.util ?? null, 60, 90)}</td>
            <td class="num">${d.await_ms === undefined ? '—' : d.await_ms + ' ms'}</td></tr>`).join(''));
        set('filesystems', (r.filesystems || []).map((f) => {
            const used = f.total ? (f.used / f.total) * 100 : 0;
            return `<tr><td class="mono">${esc(f.device)}</td><td class="mono">${esc(f.mount)}</td><td>${esc(f.type)}</td>
                <td class="metric-cell"><span class="num">${bytes(f.used)} / ${bytes(f.total)}</span>${meter(used, 80, 92)}</td>
                <td class="num">${pct(f.inodes_used_pct)}</td></tr>`;
        }).join(''));
    }

    function renderNetwork(r) {
        set('network', (r.network || []).map((n) => `<tr><td class="mono">${esc(n.name)}</td>
            <td class="num">${bits(n.rx_bps)}</td><td class="num">${bits(n.tx_bps)}</td>
            <td class="num muted">${bytes(n.rx_total)}</td><td class="num muted">${bytes(n.tx_total)}</td></tr>`).join(''));
    }

    function renderServices() {
        const rows = [...services].sort((a, b) => {
            const x = a[sort.key] ?? -1;
            const y = b[sort.key] ?? -1;
            return (typeof x === 'string' ? x.localeCompare(y) : x - y) * sort.dir;
        });
        set('services', rows.length ? rows.map((s) => {
            const name = s.url ? `<a href="${esc(s.url)}"><strong>${esc(s.name)}</strong></a>` : `<strong>${esc(s.name)}</strong> <span class="hint">(${esc(L.unknown)})</span>`;
            const memPct = s.memory && s.memory_limit ? (s.memory / s.memory_limit) * 100 : null;
            return `<tr>
                <td>${name}${s.customer ? `<div class="hint">${esc(s.customer)}</div>` : ''}</td>
                <td><span class="pill ${s.kind === 'app' ? 'info' : 'neutral'}">${esc(L.kinds[s.kind] || s.kind)}</span></td>
                <td class="metric-cell"><span class="num">${pct(s.cpu_pct)}</span>${meter(s.cpu_host_pct ?? null)}</td>
                <td class="num"><span class="${(s.cpu_wait_pct || 0) >= 10 ? 'pill warning plain' : ''}">${pct(s.cpu_wait_pct)}</span></td>
                <td class="num">${pct(s.throttled_pct)}</td>
                <td class="metric-cell"><span class="num">${bytes(s.memory)}${s.memory_limit ? ` / ${bytes(s.memory_limit)}` : ''}</span>${memPct !== null ? meter(memPct, 85, 95) : ''}</td>
                <td class="num">${rate(s.read_bps)}</td>
                <td class="num">${rate(s.write_bps)}</td>
                <td class="num">${s.pids ?? '—'}</td></tr>`;
        }).join('') : `<tr><td colspan="9" class="muted" style="text-align:center;padding:20px">${esc(L.noServices)}</td></tr>`);
        root.querySelectorAll('[data-table="services"] th[data-sort]').forEach((th) => {
            th.setAttribute('aria-sort', th.dataset.sort === sort.key ? (sort.dir > 0 ? 'ascending' : 'descending') : 'none');
        });
    }

    function renderProcesses(r) {
        set('processes', (r.processes || []).map((p) => `<tr>
            <td class="mono muted">${p.pid}</td><td class="mono">${esc(p.name)}</td>
            <td class="metric-cell"><span class="num">${pct(p.cpu_pct)}</span>${meter(Math.min(100, p.cpu_pct))}</td>
            <td class="num">${bytes(p.rss)}</td><td class="num">${p.threads}</td>
            <td>${owner(p.owner)}</td></tr>`).join(''));
    }

    // --- pętla odświeżania ------------------------------------------------------

    async function refresh() {
        try {
            const res = await fetch(root.dataset.url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) throw new Error(data.error || data.message || res.statusText);
            errorBox.hidden = true;
            statusPill.className = 'pill ok';
            statusPill.textContent = L.live;
            renderTiles(data);
            renderCpu(data);
            renderMemory(data);
            renderTemps(data);
            renderDisks(data);
            renderNetwork(data);
            services = data.services || [];
            renderServices();
            renderProcesses(data);
        } catch (e) {
            statusPill.className = 'pill critical';
            statusPill.textContent = L.offline;
            errorBox.hidden = false;
            errorBox.textContent = e.message;
        } finally {
            timer = setTimeout(refresh, document.hidden ? INTERVAL * 4 : INTERVAL);
        }
    }

    root.querySelectorAll('[data-table="services"] th[data-sort]').forEach((th) => {
        th.style.cursor = 'pointer';
        th.addEventListener('click', () => {
            const key = th.dataset.sort;
            sort = { key, dir: sort.key === key ? -sort.dir : (key === 'name' || key === 'kind' ? 1 : -1) };
            renderServices();
        });
    });
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) { clearTimeout(timer); refresh(); }
    });

    refresh();
})();
