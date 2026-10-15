/*
 * Klucze dostępu (passkeys / FIDO2) — strona przeglądarki. Opcje i
 * weryfikacja są po stronie panelu; tu tylko zamiana base64url ↔ bajty
 * i wywołanie navigator.credentials.
 *
 * Przyciski: <button data-webauthn="login|verify|register" data-options="URL" data-finish="URL">
 */
(function () {
    const b64uToBuf = (s) => {
        const pad = '='.repeat((4 - (s.length % 4)) % 4);
        const bin = atob((s + pad).replace(/-/g, '+').replace(/_/g, '/'));
        return Uint8Array.from(bin, (c) => c.charCodeAt(0)).buffer;
    };
    const bufToB64u = (buf) => btoa(String.fromCharCode(...new Uint8Array(buf))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    const csrf = () => document.querySelector('meta[name=csrf-token]')?.content || '';

    async function post(url, body) {
        const res = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
            body: JSON.stringify(body || {}),
            credentials: 'same-origin',
        });
        const json = await res.json().catch(() => ({}));
        if (!res.ok) throw new Error(json.message || res.statusText);
        return json;
    }

    async function register(optionsUrl, finishUrl, name) {
        const opts = await post(optionsUrl);
        opts.challenge = b64uToBuf(opts.challenge);
        opts.user.id = b64uToBuf(opts.user.id);
        opts.excludeCredentials = (opts.excludeCredentials || []).map((c) => ({ ...c, id: b64uToBuf(c.id) }));
        const cred = await navigator.credentials.create({ publicKey: opts });
        return post(finishUrl, {
            name,
            id: cred.id,
            clientDataJSON: bufToB64u(cred.response.clientDataJSON),
            attestationObject: bufToB64u(cred.response.attestationObject),
            transports: cred.response.getTransports ? cred.response.getTransports() : [],
        });
    }

    async function authenticate(optionsUrl, finishUrl) {
        const opts = await post(optionsUrl);
        opts.challenge = b64uToBuf(opts.challenge);
        opts.allowCredentials = (opts.allowCredentials || []).map((c) => ({ ...c, id: b64uToBuf(c.id) }));
        const cred = await navigator.credentials.get({ publicKey: opts });
        return post(finishUrl, {
            id: cred.id,
            clientDataJSON: bufToB64u(cred.response.clientDataJSON),
            authenticatorData: bufToB64u(cred.response.authenticatorData),
            signature: bufToB64u(cred.response.signature),
            userHandle: cred.response.userHandle ? bufToB64u(cred.response.userHandle) : null,
        });
    }

    window.vhWebAuthn = { register, authenticate, supported: !!(window.PublicKeyCredential && navigator.credentials) };

    document.addEventListener('click', async (e) => {
        const btn = e.target.closest('[data-webauthn]');
        if (!btn) return;
        e.preventDefault();
        const out = document.getElementById(btn.dataset.error || 'webauthn-error');
        const show = (msg) => { if (out) { out.textContent = msg; out.hidden = !msg; } };
        show('');
        if (!window.vhWebAuthn.supported) { show(btn.dataset.unsupported || 'WebAuthn is not supported.'); return; }
        btn.disabled = true;
        try {
            let result;
            if (btn.dataset.webauthn === 'register') {
                const input = document.getElementById(btn.dataset.nameInput || 'passkey-name');
                result = await register(btn.dataset.options, btn.dataset.finish, input ? input.value : '');
            } else {
                result = await authenticate(btn.dataset.options, btn.dataset.finish);
            }
            location.href = result.redirect || location.href;
        } catch (err) {
            // Anulowanie w oknie przeglądarki to NotAllowedError — bez straszenia użytkownika.
            show(err && err.name === 'NotAllowedError' ? (btn.dataset.cancelled || '') : (err.message || String(err)));
            btn.disabled = false;
        }
    });
})();
