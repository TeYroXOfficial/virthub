/*
 * Ekran postępu długiej operacji (tworzenie/reinstalacja VPS, instalacja
 * aplikacji, modpacka, loadera) — wspólny dla maszyn i aplikacji.
 *
 * Kroki idą za etapem, który zgłasza węzeł (stage + procent); gdy etapu
 * brak (starszy agent, chwilowy brak łączności), przesuwają się według
 * typowego czasu trwania (data-durations). Po zakończeniu strona się odświeża.
 *
 *   vhProgressPanel(element, {
 *       running(data) → bool           — czy operacja jeszcze trwa
 *       failure(data) → string|null    — komunikat błędu po zakończeniu
 *       aliases: { stage: 'krok' }     — etapy węzła bez własnego kroku
 *       details: { stage: 'opis' }     — opis etapu zamiast nazwy kroku
 *       detailPrefix: { stage: 'Tekst: ' } — etap z opisem od węzła (np. MB)
 *       doneTitle, failTitle
 *   })
 */
(() => {
    'use strict';
    const vhT = (s) => (window.VH_T && window.VH_T[s]) || s;

    window.vhPoll = (url, onData) => {
        const started = Date.now();
        const tick = async () => {
            let delay = Date.now() - started > 120000 ? 8000 : 2500;
            try {
                const res = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                if (res.ok && onData(await res.json()) === false) return;
            } catch (_) {
                delay = 5000; // chwilowy brak sieci — próbujemy dalej
            }
            setTimeout(tick, delay);
        };
        setTimeout(tick, 400);
    };

    window.vhProgressPanel = (progress, opts) => {
        const steps = [...progress.querySelectorAll('[data-step]')];
        const keys = steps.map((s) => s.dataset.step);
        const durations = JSON.parse(progress.dataset.durations || '[]');
        const total = durations.reduce((a, b) => a + b, 0) || 1;
        const bar = progress.querySelector('[data-progress-bar]');
        const barWrap = bar.parentElement;
        const sub = progress.querySelector('[data-progress-sub]');
        const t0 = Date.now() - (parseInt(progress.dataset.elapsed, 10) || 0) * 1000;
        const aliases = { prepare: keys[0], stop: keys[0], ...(opts.aliases || {}) };
        const details = {
            pending: vhT('Zlecenie czeka w kolejce panelu…'),
            queued: vhT('Węzeł przyjął zlecenie — zaraz zacznie.'),
            ...(opts.details || {}),
        };
        const prefixes = opts.detailPrefix || {};

        let node = null;          // { stage, percent, since, detail } z ostatniego odpytania
        let shown = 0;            // wartość paska na ekranie (wygładzana)
        let finished = false;

        const stepIndex = (stage) => {
            const key = keys.includes(stage) ? stage : aliases[stage];
            return key === undefined ? -1 : keys.indexOf(key);
        };

        const render = () => {
            if (finished) return;
            const elapsed = (Date.now() - t0) / 1000;
            let current;
            let target;

            if (node && stepIndex(node.stage) >= 0) {
                current = stepIndex(node.stage);
                // Od zgłoszonego procentu powoli w stronę następnego etapu —
                // pasek żyje, ale nie wyprzedza węzła.
                const base = node.percent ?? Math.round((current / steps.length) * 90);
                const cap = Math.min(96, base + 14);
                const inStage = (Date.now() - node.since) / 1000;
                target = base + (cap - base) * (1 - Math.exp(-inStage / 25));
                sub.textContent = prefixes[node.stage] && node.detail
                    ? prefixes[node.stage] + node.detail
                    : details[node.stage] || steps[current].textContent.trim() + '…';
            } else {
                let acc = 0;
                current = steps.length - 1;
                for (let i = 0; i < durations.length; i++) {
                    acc += durations[i];
                    if (elapsed < acc) { current = i; break; }
                }
                target = Math.min(95, 95 * (1 - Math.exp(-elapsed / (total * 0.8))));
                if (node && details[node.stage]) {
                    current = 0;
                    target = Math.min(target, 4);
                    sub.textContent = details[node.stage];
                }
            }

            steps.forEach((s, i) => {
                s.classList.toggle('done', i < current);
                s.classList.toggle('active', i === current);
            });
            shown += (target - shown) * 0.06;
            bar.style.width = shown.toFixed(1) + '%';
            barWrap.setAttribute('aria-valuenow', String(Math.round(shown)));
            requestAnimationFrame(render);
        };
        requestAnimationFrame(render);

        const finish = (ok, message) => {
            finished = true;
            steps.forEach((s) => { s.classList.remove('active'); s.classList.toggle('done', ok); });
            bar.style.width = '100%';
            progress.classList.add(ok ? 'is-done' : 'is-failed');
            progress.querySelector('[data-orb-icon]').innerHTML = ok
                ? '<svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>'
                : '<svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>';
            progress.querySelector('[data-progress-title]').textContent = ok
                ? (opts.doneTitle || vhT('Gotowe! Serwer działa.'))
                : (opts.failTitle || vhT('Operacja nie powiodła się'));
            sub.textContent = message;
            setTimeout(() => location.reload(), ok ? 1400 : 3000);
        };

        window.vhPoll(progress.dataset.statusUrl, (data) => {
            const job = data.job || {};
            if (job.stage && (!node || node.stage !== job.stage || node.percent !== job.stage_progress)) {
                node = { stage: job.stage, percent: job.stage_progress, since: Date.now() };
            }
            if (node) node.detail = job.stage_detail;
            if (opts.running(data)) return true;
            const error = opts.failure(data);
            finish(!error, error || vhT('Odświeżam panel…'));
            return false;
        });
    };
})();
