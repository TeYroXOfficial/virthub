{{-- Katalog treści: pasek ładowania po kliknięciu i wstępne wczytanie strony
     paczki po najechaniu kursorem (link rel=prefetch) — przejście jest wtedy natychmiastowe. --}}
@once
    @push('scripts')
        <script>
            (function () {
                const busy = () => document.body.classList.add('is-loading');
                window.addEventListener('pageshow', () => document.body.classList.remove('is-loading'));
                const fetched = new Set();
                const prefetch = (url) => {
                    if (!url || fetched.has(url) || fetched.size > 30) return;
                    fetched.add(url);
                    const link = document.createElement('link');
                    link.rel = 'prefetch';
                    link.href = url;
                    document.head.appendChild(link);
                };
                document.querySelectorAll('.catalog-card, .pager a, .segmented a, [data-prefetch]').forEach((a) => {
                    let timer;
                    a.addEventListener('click', busy);
                    a.addEventListener('mouseenter', () => { timer = setTimeout(() => prefetch(a.href), 80); });
                    a.addEventListener('mouseleave', () => clearTimeout(timer));
                    a.addEventListener('touchstart', () => prefetch(a.href), { passive: true });
                });
                // Wyszukiwanie i instalacja — po potwierdzeniu (confirm w onsubmit działa wcześniej).
                document.addEventListener('submit', (e) => {
                    if (!e.defaultPrevented && e.target.closest('.catalog-search, .content-form, .version-list')) busy();
                });
            })();
        </script>
    @endpush
@endonce
