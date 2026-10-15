/*
 * Narzędzia konsoli VNC (noVNC): wpisywanie tekstu i zapisane fragmenty,
 * panel klawiszy z przyklejanymi modyfikatorami, klawiatura ekranowa (telefon),
 * presety jakości, tryb tylko do podglądu, zrzut ekranu, nagrywanie do WebM
 * i obraz w obrazie. Wszystko po stronie przeglądarki — przez tunel idzie
 * tylko zwykły protokół VNC.
 */

const KEY = {
    Escape: 0xff1b, Tab: 0xff09, BackSpace: 0xff08, Return: 0xff0d, Delete: 0xffff, Insert: 0xff63,
    Home: 0xff50, End: 0xff57, PageUp: 0xff55, PageDown: 0xff56,
    Left: 0xff51, Up: 0xff52, Right: 0xff53, Down: 0xff54, Print: 0xff61,
    Shift: 0xffe1, Ctrl: 0xffe3, Alt: 0xffe9, Super: 0xffeb,
};
for (let i = 1; i <= 12; i++) KEY['F' + i] = 0xffbe + i - 1;

/** Presety jakości: [jakość JPEG 0–9, kompresja 0–9]. */
export const QUALITY = { best: [9, 0], balanced: [6, 2], low: [2, 9] };

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const store = {
    get(key, fallback) { try { return localStorage.getItem('vh-console-' + key) ?? fallback; } catch (e) { return fallback; } },
    set(key, value) { try { localStorage.setItem('vh-console-' + key, value); } catch (e) { /* tryb prywatny */ } },
};

function keysymFor(ch) {
    if (ch === '\n') return KEY.Return;
    if (ch === '\t') return KEY.Tab;
    const code = ch.codePointAt(0);
    return code < 0x100 ? code : 0x01000000 + code;
}

function download(blob, name) {
    const url = URL.createObjectURL(blob);
    const a = Object.assign(document.createElement('a'), { href: url, download: name });
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
}

function stamp() {
    const d = new Date();
    const p = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}${p(d.getMonth() + 1)}${p(d.getDate())}-${p(d.getHours())}${p(d.getMinutes())}${p(d.getSeconds())}`;
}

/**
 * @param {object} rfb   obiekt RFB z noVNC
 * @param {object} cfg   { root, hostname, t: {…etykiety}, snippets: [], snippetUrl, csrf }
 */
export function initVncTools(rfb, cfg) {
    const $ = (sel) => cfg.root.querySelector(sel);
    const t = cfg.t;
    const canvas = () => cfg.root.querySelector('#console-screen canvas');
    let busyTyping = false;

    // --- wpisywanie tekstu -------------------------------------------------------
    async function typeText(text) {
        if (!text || busyTyping || rfb.viewOnly) return;
        busyTyping = true;
        try {
            let i = 0;
            for (const ch of text) {
                if (ch === '\r') continue;
                rfb.sendKey(keysymFor(ch));
                // Krótka przerwa co kilka znaków — długi tekst nie gubi liter w gościu.
                if (++i % 8 === 0) await sleep(12);
            }
        } finally {
            busyTyping = false;
            rfb.focus();
        }
    }
    cfg.typeText = typeText;

    $('#btn-paste')?.addEventListener('click', async () => {
        let text = '';
        // Odczyt schowka bywa zablokowany albo czeka na zgodę bez końca — wtedy pytamy o tekst.
        try { text = await Promise.race([navigator.clipboard.readText(), sleep(800).then(() => '')]); } catch (e) { /* brak uprawnień */ }
        if (!text) text = (await vhPrompt(t.typePrompt, '', { title: t.typeTitle, okLabel: t.typeOk })) || '';
        typeText(text);
    });

    // --- panel klawiszy ------------------------------------------------------------
    const sticky = new Set();
    const keysPanel = $('#console-keys');
    $('#btn-keys')?.addEventListener('click', (e) => {
        keysPanel.hidden = !keysPanel.hidden;
        e.currentTarget.setAttribute('aria-pressed', String(!keysPanel.hidden));
    });
    keysPanel?.addEventListener('click', (e) => {
        const btn = e.target.closest('button[data-key], button[data-mod], button[data-combo]');
        if (!btn || rfb.viewOnly) return;
        if (btn.dataset.mod) {
            const mod = btn.dataset.mod;
            sticky.has(mod) ? sticky.delete(mod) : sticky.add(mod);
            btn.setAttribute('aria-pressed', String(sticky.has(mod)));
            return;
        }
        const combo = btn.dataset.combo ? btn.dataset.combo.split('+') : [...sticky, btn.dataset.key];
        const mods = combo.slice(0, -1).map((m) => KEY[m]);
        const key = KEY[combo[combo.length - 1]] ?? keysymFor(combo[combo.length - 1]);
        mods.forEach((m) => rfb.sendKey(m, null, true));
        rfb.sendKey(key);
        mods.reverse().forEach((m) => rfb.sendKey(m, null, false));
        sticky.clear();
        keysPanel.querySelectorAll('[data-mod]').forEach((b) => b.setAttribute('aria-pressed', 'false'));
        rfb.focus();
    });
    $('#btn-cad')?.addEventListener('click', () => rfb.sendCtrlAltDel());

    // --- klawiatura ekranowa (telefony, tablety) -----------------------------------
    const vkInput = $('#console-vk');
    $('#btn-vk')?.addEventListener('click', () => {
        vkInput.value = '';
        vkInput.focus({ preventScroll: true });
    });
    vkInput?.addEventListener('input', () => {
        const text = vkInput.value;
        vkInput.value = '';
        typeText(text);
    });
    vkInput?.addEventListener('keydown', (e) => {
        const special = { Backspace: KEY.BackSpace, Enter: KEY.Return, Tab: KEY.Tab, Escape: KEY.Escape,
            ArrowLeft: KEY.Left, ArrowRight: KEY.Right, ArrowUp: KEY.Up, ArrowDown: KEY.Down };
        if (special[e.key] !== undefined) {
            e.preventDefault();
            if (!rfb.viewOnly) rfb.sendKey(special[e.key]);
        }
    });

    // --- zapisane fragmenty ----------------------------------------------------------
    const list = $('#snippet-list');
    function renderSnippets(items) {
        cfg.snippets = items;
        list.replaceChildren();
        if (!items.length) {
            list.append(Object.assign(document.createElement('li'), { className: 'muted', textContent: t.noSnippets }));
            return;
        }
        for (const s of items) {
            const li = document.createElement('li');
            const type = Object.assign(document.createElement('button'), { type: 'button', className: 'snippet-type', textContent: s.name, title: t.typeSnippet });
            type.addEventListener('click', () => typeText(s.body));
            const del = Object.assign(document.createElement('button'), { type: 'button', className: 'btn btn-sm snippet-del', textContent: '×', title: t.deleteSnippet });
            del.setAttribute('aria-label', t.deleteSnippet + ': ' + s.name);
            del.addEventListener('click', async () => {
                if (!(await vhConfirm(t.deleteConfirm.replace(':name', s.name), { danger: true }))) return;
                const res = await fetch(cfg.snippetUrl + '/' + s.id, { method: 'DELETE', headers: { 'X-CSRF-TOKEN': cfg.csrf, Accept: 'application/json' } });
                if (res.ok) renderSnippets((await res.json()).data);
            });
            li.append(type, del);
            list.append(li);
        }
    }
    renderSnippets(cfg.snippets || []);
    $('#snippet-form')?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const form = e.currentTarget;
        const res = await fetch(cfg.snippetUrl, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': cfg.csrf, Accept: 'application/json', 'Content-Type': 'application/json' },
            body: JSON.stringify({ name: form.elements.name.value, body: form.elements.body.value }),
        });
        const json = await res.json().catch(() => ({}));
        if (!res.ok) {
            vhConfirm(json.message || t.saveFailed, { title: t.snippets, okLabel: 'OK' });
            return;
        }
        form.reset();
        renderSnippets(json.data);
    });
    $('#btn-snippets')?.addEventListener('click', (e) => {
        const panel = $('#console-snippets');
        panel.hidden = !panel.hidden;
        e.currentTarget.setAttribute('aria-pressed', String(!panel.hidden));
    });

    // --- jakość i podgląd ------------------------------------------------------------
    const quality = $('#console-quality');
    const applyQuality = (name) => {
        const [q, c] = QUALITY[name] || QUALITY.balanced;
        rfb.qualityLevel = q;
        rfb.compressionLevel = c;
    };
    if (quality) {
        quality.value = QUALITY[store.get('quality', 'balanced')] ? store.get('quality', 'balanced') : 'balanced';
        applyQuality(quality.value);
        quality.addEventListener('change', () => { applyQuality(quality.value); store.set('quality', quality.value); });
    }
    const viewBtn = $('#btn-view-only');
    viewBtn?.addEventListener('click', () => {
        rfb.viewOnly = !rfb.viewOnly;
        viewBtn.setAttribute('aria-pressed', String(rfb.viewOnly));
        cfg.root.classList.toggle('view-only', rfb.viewOnly);
    });

    // --- zrzut ekranu ------------------------------------------------------------------
    $('#btn-shot')?.addEventListener('click', () => {
        canvas()?.toBlob((blob) => blob && download(blob, `${cfg.hostname}-${stamp()}.png`), 'image/png');
    });

    // --- nagrywanie ----------------------------------------------------------------------
    const recBtn = $('#btn-record');
    let recorder = null;
    let chunks = [];
    const recLabel = recBtn?.querySelector('span');
    if (recBtn && !('MediaRecorder' in window && HTMLCanvasElement.prototype.captureStream)) recBtn.hidden = true;
    recBtn?.addEventListener('click', () => {
        if (recorder) { recorder.stop(); return; }
        const c = canvas();
        if (!c) return;
        const type = ['video/webm;codecs=vp9', 'video/webm;codecs=vp8', 'video/webm'].find((m) => MediaRecorder.isTypeSupported(m));
        recorder = new MediaRecorder(c.captureStream(15), type ? { mimeType: type } : undefined);
        chunks = [];
        recorder.ondataavailable = (e) => e.data.size && chunks.push(e.data);
        recorder.onstop = () => {
            download(new Blob(chunks, { type: 'video/webm' }), `${cfg.hostname}-${stamp()}.webm`);
            recorder = null;
            recBtn.classList.remove('recording');
            recBtn.setAttribute('aria-pressed', 'false');
            if (recLabel) recLabel.textContent = t.record;
        };
        recorder.start(1000);
        recBtn.classList.add('recording');
        recBtn.setAttribute('aria-pressed', 'true');
        if (recLabel) recLabel.textContent = t.stopRecording;
    });
    rfb.addEventListener('disconnect', () => recorder?.stop());

    // --- obraz w obrazie -----------------------------------------------------------------
    const pipBtn = $('#btn-pip');
    let pipVideo = null;
    if (pipBtn && !(document.pictureInPictureEnabled && HTMLCanvasElement.prototype.captureStream)) pipBtn.hidden = true;
    pipBtn?.addEventListener('click', async () => {
        if (document.pictureInPictureElement) { await document.exitPictureInPicture(); return; }
        const c = canvas();
        if (!c) return;
        pipVideo ??= Object.assign(document.createElement('video'), { muted: true, playsInline: true });
        pipVideo.srcObject = c.captureStream(15);
        await pipVideo.play();
        try { await pipVideo.requestPictureInPicture(); } catch (e) { /* przeglądarka odmówiła */ }
    });

    return { typeText };
}
