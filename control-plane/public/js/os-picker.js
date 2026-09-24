/* Wybór systemu: kliknięcie kafelka wybiera grupę, lista — wersję. Do formularza
   trafia id szablonu w ukrytym polu „template". */
(() => {
    'use strict';
    document.querySelectorAll('[data-os-picker]').forEach((picker) => {
        const value = picker.querySelector('[data-os-value]');
        const cards = [...picker.querySelectorAll('[data-os-group]')];

        const templateOf = (card) =>
            card.querySelector('[data-os-version]')?.value ?? card.querySelector('[data-os-single]')?.dataset.osSingle;

        const pick = (card) => {
            cards.forEach((c) => {
                c.classList.toggle('is-selected', c === card);
                c.setAttribute('aria-checked', String(c === card));
            });
            value.value = templateOf(card) || '';
        };

        cards.forEach((card) => {
            card.addEventListener('click', (e) => { if (!e.target.matches('select, option')) pick(card); });
            card.addEventListener('keydown', (e) => {
                if (e.key === ' ' || e.key === 'Enter') { e.preventDefault(); pick(card); }
            });
            const select = card.querySelector('[data-os-version]');
            select?.addEventListener('change', () => pick(card));
            select?.addEventListener('focus', () => pick(card));
        });

        // Formularz bez wybranego systemu — podpowiedź zamiast pustego wysłania.
        picker.closest('form')?.addEventListener('submit', (e) => {
            if (!value.value) {
                e.preventDefault();
                picker.classList.add('needs-choice');
                picker.scrollIntoView({ block: 'center', behavior: 'smooth' });
            }
        });
    });
})();
