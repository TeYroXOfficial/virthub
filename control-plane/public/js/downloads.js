/* Paski postępu pobierań na węzłach (szablony, ISO). Odpytuje panel, który
   pyta węzły; gdy któreś pobieranie się skończy — odświeża stronę. */
(() => {
    'use strict';
    const url = document.currentScript?.dataset.statusUrl;
    if (!url || !document.querySelector('[data-download]')) return;

    const tick = async () => {
        let rows;
        try {
            const res = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (res.ok) rows = (await res.json()).data || [];
        } catch (_) { /* chwilowy brak sieci */ }
        if (rows === undefined) {
            setTimeout(tick, 5000); // bez odpowiedzi nie wiemy nic — nie odświeżamy
            return;
        }

        let finished = false;
        for (const row of rows) {
            const el = document.querySelector(`[data-download="${row.kind}-${row.id}"]`);
            if (!el) continue;
            if (row.finished) { finished = true; continue; }
            const bar = el.querySelector('[data-dl-bar]');
            if (row.progress !== null) {
                bar.parentElement.classList.remove('is-indeterminate');
                bar.style.width = row.progress + '%';
                el.querySelector('[data-dl-pct]').textContent = row.progress + '%';
            }
            el.querySelector('[data-dl-detail]').textContent = row.detail || '';
        }
        // Pobieranie zniknęło z listy trwających albo się skończyło — pokaż wynik.
        const known = new Set(rows.map((r) => `${r.kind}-${r.id}`));
        const vanished = [...document.querySelectorAll('[data-download]')].some((el) => !known.has(el.dataset.download));
        if (finished || vanished) {
            setTimeout(() => location.reload(), 800);
            return;
        }
        setTimeout(tick, 2000);
    };
    setTimeout(tick, 1000);
})();
