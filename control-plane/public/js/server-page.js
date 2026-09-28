/*
 * Strona maszyny: zakładki, okno reinstalacji, ekran postępu i odświeżanie
 * po zakończeniu operacji. Bez zależności — panel nie ma kroku budowania.
 */
(() => {
    'use strict';
    const vhT = (s) => (window.VH_T && window.VH_T[s]) || s;

    // --- zakładki -----------------------------------------------------------
    const tabs = [...document.querySelectorAll('[data-tab]')];
    const panels = [...document.querySelectorAll('[data-tab-panel]')];

    const activate = (name, updateHash) => {
        if (!panels.some((p) => p.dataset.tabPanel === name)) return;
        panels.forEach((p) => { p.hidden = p.dataset.tabPanel !== name; });
        tabs.forEach((t) => t.setAttribute('aria-selected', String(t.dataset.tab === name)));
        if (updateHash) history.replaceState(null, '', '#' + name);
    };

    const first = panels[0]?.dataset.tabPanel;
    const fromHash = () => {
        const hash = decodeURIComponent(location.hash.slice(1));
        if (!hash) return first;
        if (panels.some((p) => p.dataset.tabPanel === hash)) return hash;
        // Kotwica elementu wewnątrz zakładki (np. #firewall, #iso, #password).
        const target = document.getElementById(hash);
        return target?.closest('[data-tab-panel]')?.dataset.tabPanel ?? first;
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
            submit.textContent = vhT('Uruchamiam reinstalację…');
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
        setTimeout(tick, 400);
    };

    // Ekran postępu (wspólny z aplikacjami — progress-panel.js).
    const progress = document.getElementById('progress');
    if (progress && window.vhProgressPanel) {
        window.vhProgressPanel(progress, {
            aliases: { download: 'image' },
            details: {
                download: vhT('Węzeł pobiera obraz systemu. Przy pierwszym użyciu tego systemu może to potrwać kilka minut.'),
            },
            detailPrefix: { download: vhT('Węzeł pobiera obraz systemu: ') },
            running: (data) => data.transitioning,
            failure: (data) => (data.state === 'error' || data.job?.status === 'failed')
                ? (data.job?.error || vhT('Szczegóły pojawią się na stronie.'))
                : null,
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
