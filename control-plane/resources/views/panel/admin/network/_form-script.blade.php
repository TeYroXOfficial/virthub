{{-- Pokazuje tylko pola pasujące do wybranych opcji. Bez JS widać wszystkie pola i formularz działa tak samo. --}}
<script>
    document.querySelectorAll('form[data-pool-form]').forEach((form) => {
        const val = (sel) => form.querySelector(sel)?.value;
        const sync = () => {
            const scope = val('[data-scope-select]');
            const type = val('[name=type]');
            const fill = val('[name=fill]:checked');
            const dns = val('[data-dns-select]');
            form.querySelectorAll('[data-scope]').forEach((el) => { el.hidden = scope && el.dataset.scope !== scope; });
            form.querySelectorAll('[data-type]').forEach((el) => { el.hidden = type && el.dataset.type !== type; });
            form.querySelectorAll('[data-fill]').forEach((el) => { el.hidden = fill && !el.dataset.fill.split(' ').includes(fill); });
            form.querySelectorAll('[data-dns-custom]').forEach((el) => { el.hidden = dns && dns !== 'custom'; });
        };
        form.addEventListener('change', sync);
        sync();
    });
    document.querySelectorAll('form[data-address-form]').forEach((form) => {
        const sync = () => {
            const mode = form.querySelector('[name=mode]:checked')?.value;
            form.querySelectorAll('[data-mode]').forEach((el) => { el.hidden = mode && el.dataset.mode !== mode; });
        };
        form.addEventListener('change', sync);
        sync();
    });
</script>
