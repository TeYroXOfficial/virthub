/*
 * Okna potwierdzeń, pytań i komunikatów w stylu panelu — zamiast systemowych
 * confirm()/prompt()/alert() przeglądarki.
 *
 *   <form data-confirm="Usunąć plik?">          — pytanie przed wysłaniem
 *   <button data-confirm="…">                    — pytanie tylko dla tego przycisku
 *   data-confirm-ok="Usuń"                       — własny napis przycisku
 *   await vhConfirm('Tekst?', { danger: true })  — z kodu (Promise<boolean>)
 *   await vhPrompt('Nowa nazwa:', 'plik.txt')    — Promise<string|null>
 *   await vhAlert('Coś poszło nie tak')          — Promise<void>
 *
 * Przycisk potwierdzenia jest czerwony, gdy akcja jest niebezpieczna
 * (przycisk wysyłający ma klasę btn-danger…) albo gdy podano danger: true.
 */
(() => {
    'use strict';
    const t = (s) => (window.VH_T && window.VH_T[s]) || s;

    function open({ title, message, input, okLabel, cancel = true, danger = false }) {
        return new Promise((resolve) => {
            const dialog = document.createElement('dialog');
            dialog.className = 'modal vh-dialog';
            dialog.setAttribute('aria-labelledby', 'vh-dialog-title');
            dialog.innerHTML = `
                <form method="dialog" class="modal-body">
                    <div class="modal-head"><div>
                        <h2 id="vh-dialog-title"></h2>
                        <p class="vh-dialog-message"></p>
                    </div></div>
                    <input type="text" class="vh-dialog-input" hidden>
                    <div class="modal-foot">
                        <button type="button" class="btn" data-cancel></button>
                        <button type="submit" class="btn" data-ok></button>
                    </div>
                </form>`;
            dialog.querySelector('h2').textContent = title;
            dialog.querySelector('.vh-dialog-message').textContent = message;
            const field = dialog.querySelector('.vh-dialog-input');
            const ok = dialog.querySelector('[data-ok]');
            const no = dialog.querySelector('[data-cancel]');
            ok.textContent = okLabel || t('OK');
            ok.classList.add(danger ? 'btn-danger-solid' : 'btn-primary');
            no.textContent = t('Anuluj');
            no.hidden = !cancel;
            if (input !== undefined) {
                field.hidden = false;
                field.value = input ?? '';
            }

            let result = null;
            ok.addEventListener('click', () => { result = input !== undefined ? field.value : true; });
            no.addEventListener('click', () => { result = null; dialog.close(); });
            dialog.addEventListener('click', (e) => { if (e.target === dialog && cancel) dialog.close(); });
            dialog.addEventListener('close', () => {
                dialog.remove();
                resolve(input !== undefined ? result : result === true || !cancel);
            });

            document.body.appendChild(dialog);
            dialog.showModal();
            (input !== undefined ? field : (danger ? no : ok)).focus();
            if (input !== undefined) field.select();
        });
    }

    window.vhConfirm = (message, opts = {}) => open({ title: opts.title || t('Potwierdź'), message, ...opts });
    window.vhPrompt = (message, value = '', opts = {}) => open({ title: opts.title || t('Podaj wartość'), message, input: value, ...opts });
    window.vhAlert = (message, opts = {}) => open({ title: opts.title || t('Uwaga'), message, cancel: false, ...opts }).then(() => undefined);

    const isDanger = (el) => !!el && /\bbtn-danger/.test(el.className);

    // Formularze i przyciski z data-confirm: pytamy, a po zgodzie wysyłamy
    // ten sam formularz tym samym przyciskiem (zachowuje name/value przycisku).
    document.addEventListener('submit', async (e) => {
        const form = e.target;
        if (!(form instanceof HTMLFormElement) || form.dataset.vhConfirmed === '1') return;
        const submitter = e.submitter;
        const message = submitter?.dataset.confirm || form.dataset.confirm;
        if (!message) return;
        e.preventDefault();
        e.stopImmediatePropagation();
        const danger = isDanger(submitter) || isDanger(form.querySelector('[type=submit]')) || 'confirmDanger' in form.dataset;
        const okLabel = submitter?.dataset.confirmOk || form.dataset.confirmOk || submitter?.textContent.trim() || undefined;
        if (!(await window.vhConfirm(message, { danger, okLabel }))) return;
        form.dataset.vhConfirmed = '1';
        submitter ? form.requestSubmit(submitter) : form.requestSubmit();
        delete form.dataset.vhConfirmed;
    }, true);
})();
