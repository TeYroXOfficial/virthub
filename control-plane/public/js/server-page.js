/*
 * Strona maszyny: zakładki, okno reinstalacji, ekran postępu i odświeżanie
 * po zakończeniu operacji. Bez zależności — panel nie ma kroku budowania.
 */
(() => {
    'use strict';

    // --- zakładki -----------------------------------------------------------
    const tabs = [...document.querySelectorAll('[data-tab]')];
    const panels = [...document.querySelectorAll('[data-tab-panel]')];

    const activate = (name, updateHash) => {
        if (!panels.some((p) => p.dataset.tabPanel === name)) return;
        panels.forEach((p) => { p.hidden = p.dataset.tabPanel !== name; });
        tabs.forEach((t) => t.setAttribute('aria-selected', String(t.dataset.tab === name)));
        if (updateHash) history.replaceState(null, '', '#' + name);
    };

    const fromHash = () => {
        const hash = decodeURIComponent(location.hash.slice(1));
        if (!hash) return 'overview';
        if (panels.some((p) => p.dataset.tabPanel === hash)) return hash;
        // Kotwica elementu wewnątrz zakładki (np. #firewall, #iso, #password).
        const target = document.getElementById(hash);
        return target?.closest('[data-tab-panel]')?.dataset.tabPanel ?? 'overview';
    };

    if (tabs.length) {
        tabs.forEach((t) => t.addEventListener('click', () => activate(t.dataset.tab, true)));
        activate(fromHash(), false);
        const anchor = document.getElementById(decodeURIComponent(location.hash.slice(1)));
        if (anchor && !anchor.matches('[data-tab-panel]')) anchor.scrollIntoView({ block: 'start' });
        window.addEventListener('hashchange', () => activate(fromHash(), false));
    }

    // --- okno reinstalacji --------------------------------------------------
    const modal = document.getElementById('reinstall');
    if (modal && typeof modal.showModal === 'function') {
        document.querySelectorAll('[data-open-reinstall]').forEach((b) =>
            b.addEventListener('click', () => modal.showModal()));
        modal.querySelectorAll('[data-close]').forEach((b) =>
            b.addEventListener('click', () => modal.close()));
        modal.addEventListener('click', (e) => { if (e.target === modal) modal.close(); });
        if (modal.hasAttribute('data-open')) modal.showModal();

        modal.querySelector('[data-reinstall-form]')?.addEventListener('submit', (e) => {
            const submit = e.target.querySelector('button[type=submit]');
            submit.disabled = true;
            submit.textContent = 'Uruchamiam reinstalację…';
        });
    }

    // --- odpytywanie stanu --------------------------------------------------
    const poll = (url, onData) => {
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
        setTimeout(tick, 1500);
    };

    // Ekran postępu: kroki przesuwają się według typowego czasu trwania,
    // ostatni czeka na faktyczne zakończenie — wtedy strona się odświeża.
    const progress = document.getElementById('progress');
    if (progress) {
        const steps = [...progress.querySelectorAll('[data-step]')];
        const durations = JSON.parse(progress.dataset.steps || '[]');
        const total = durations.reduce((a, b) => a + b, 0) || 1;
        const bar = progress.querySelector('[data-progress-bar]');
        const barWrap = bar.parentElement;
        const t0 = Date.now() - (parseInt(progress.dataset.elapsed, 10) || 0) * 1000;
        let finished = false;

        const render = () => {
            if (finished) return;
            const elapsed = (Date.now() - t0) / 1000;
            let acc = 0;
            let current = steps.length - 1;
            for (let i = 0; i < durations.length; i++) {
                acc += durations[i];
                if (elapsed < acc) { current = i; break; }
            }
            steps.forEach((s, i) => {
                s.classList.toggle('done', i < current);
                s.classList.toggle('active', i === current);
            });
            // Asymptotycznie do 95% — pasek nigdy nie „kończy się" przed serwerem.
            const pct = Math.min(95, 95 * (1 - Math.exp(-elapsed / (total * 0.8))));
            bar.style.width = pct.toFixed(1) + '%';
            barWrap.setAttribute('aria-valuenow', String(Math.round(pct)));
            requestAnimationFrame(render);
        };
        requestAnimationFrame(render);

        const finish = (ok, message) => {
            finished = true;
            steps.forEach((s) => { s.classList.remove('active'); s.classList.toggle('done', ok); });
            bar.style.width = '100%';
            progress.classList.add(ok ? 'is-done' : 'is-failed');
            progress.querySelector('[data-progress-title]').textContent = ok ? 'Gotowe! Serwer działa.' : 'Operacja nie powiodła się';
            progress.querySelector('[data-progress-sub]').textContent = message;
            setTimeout(() => location.reload(), ok ? 1400 : 2500);
        };

        poll(progress.dataset.statusUrl, (data) => {
            if (data.transitioning) return true;
            const failed = data.state === 'error' || data.job?.status === 'failed';
            finish(!failed, failed ? (data.job?.error || 'Szczegóły pojawią się na stronie.') : 'Odświeżam panel…');
            return false;
        });
    }

    // Krótkie operacje (hasło, ISO, zasilanie): odśwież po zakończeniu zadania.
    const watch = document.querySelector('[data-watch-job]');
    if (watch) {
        poll(watch.dataset.statusUrl, (data) => {
            if (data.job && !data.job.finished) return true;
            location.reload();
            return false;
        });
    }
})();
