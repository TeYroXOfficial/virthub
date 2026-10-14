/*
 * Dokumentacja API: przykłady kodu w kilku językach i konsola do wysyłania
 * zapytań (jak Postman). Dane endpointów i teksty interfejsu przychodzą z
 * window.VH_API (wyrenderowane przez panel w bieżącym języku).
 */
(function () {
    'use strict';

    var api = window.VH_API || {};
    var t = api.t || {};
    var byId = {};
    (api.catalog || []).forEach(function (e) { byId[e.id] = e; });

    var storage = {
        get: function (k) { try { return window.localStorage.getItem(k); } catch (e) { return null; } },
        set: function (k, v) { try { window.localStorage.setItem(k, v); } catch (e) { /* tryb prywatny */ } },
        del: function (k) { try { window.localStorage.removeItem(k); } catch (e) { /* */ } },
    };

    // --- przykłady kodu -----------------------------------------------------

    function examplePath(e) {
        var path = e.path;
        (e.path_params || []).forEach(function (p) { path = path.replace('{' + p.name + '}', encodeURIComponent(String(p.example))); });
        var query = (e.query || []).filter(function (p) { return p.example !== null && p.example !== undefined; })
            .map(function (p) { return p.name + '=' + encodeURIComponent(String(p.example)); });
        return path + (query.length ? '?' + query.join('&') : '');
    }

    function hasBody(e) { return e.body && e.body.length > 0; }

    function json(value, indent) { return JSON.stringify(value, null, indent === undefined ? 2 : indent); }

    function phpArray(value, pad) {
        pad = pad || '';
        if (Array.isArray(value)) {
            if (!value.length) return '[]';
            return '[\n' + value.map(function (v) { return pad + '    ' + phpArray(v, pad + '    '); }).join(',\n') + ',\n' + pad + ']';
        }
        if (value && typeof value === 'object') {
            var keys = Object.keys(value);
            if (!keys.length) return '[]';
            return '[\n' + keys.map(function (k) { return pad + "    '" + k + "' => " + phpArray(value[k], pad + '    '); }).join(',\n') + ',\n' + pad + ']';
        }
        if (typeof value === 'string') return "'" + value.replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'";
        if (value === null) return 'null';
        return String(value);
    }

    function pyValue(value) {
        return json(value, 4).replace(/\btrue\b/g, 'True').replace(/\bfalse\b/g, 'False').replace(/\bnull\b/g, 'None');
    }

    var samples = {
        curl: function (e, base) {
            var lines = ['curl -X ' + e.method + " '" + base + examplePath(e) + "'", "  -H 'Authorization: Bearer $TOKEN'", "  -H 'Accept: application/json'"];
            if (hasBody(e)) {
                lines.push("  -H 'Content-Type: application/json'");
                lines.push("  -d '" + json(e.example_body).replace(/'/g, "'\\''") + "'");
            }
            return lines.join(' \\\n');
        },
        php: function (e, base) {
            var out = '<?php\n// composer require guzzlehttp/guzzle\n$client = new \\GuzzleHttp\\Client([\n    \'base_uri\' => \'' + base + '/\',\n    \'headers\' => [\'Authorization\' => \'Bearer \' . getenv(\'TOKEN\'), \'Accept\' => \'application/json\'],\n]);\n\n';
            out += '$response = $client->request(\'' + e.method + '\', \'' + examplePath(e).replace(/^\//, '') + '\'' + (hasBody(e) ? ', [\n    \'json\' => ' + phpArray(e.example_body, '    ') + ',\n]' : '') + ');\n';
            return out + '$data = json_decode((string) $response->getBody(), true);\nprint_r($data);';
        },
        python: function (e, base) {
            var out = 'import os\nimport requests  # pip install requests\n\n';
            out += 'response = requests.request(\n    "' + e.method + '",\n    "' + base + examplePath(e) + '",\n    headers={"Authorization": f"Bearer {os.environ[\'TOKEN\']}", "Accept": "application/json"},\n';
            if (hasBody(e)) out += '    json=' + pyValue(e.example_body).replace(/\n/g, '\n    ') + ',\n';
            return out + '    timeout=30,\n)\nresponse.raise_for_status()\nprint(response.json())';
        },
        js: function (e, base) {
            var out = 'const response = await fetch("' + base + examplePath(e) + '", {\n  method: "' + e.method + '",\n  headers: {\n    Authorization: `Bearer ${process.env.TOKEN}`,\n    Accept: "application/json",\n';
            if (hasBody(e)) out += '    "Content-Type": "application/json",\n  },\n  body: JSON.stringify(' + json(e.example_body).replace(/\n/g, '\n  ') + '),\n});\n';
            else out += '  },\n});\n';
            return out + 'if (!response.ok) throw new Error(`HTTP ${response.status}`);\nconsole.log(await response.json());';
        },
        go: function (e, base) {
            var out = 'package main\n\nimport (\n\t"fmt"\n\t"io"\n\t"net/http"\n\t"os"\n' + (hasBody(e) ? '\t"strings"\n' : '') + ')\n\nfunc main() {\n';
            out += hasBody(e)
                ? '\tbody := strings.NewReader(`' + json(e.example_body) + '`)\n\treq, _ := http.NewRequest("' + e.method + '", "' + base + examplePath(e) + '", body)\n\treq.Header.Set("Content-Type", "application/json")\n'
                : '\treq, _ := http.NewRequest("' + e.method + '", "' + base + examplePath(e) + '", nil)\n';
            out += '\treq.Header.Set("Authorization", "Bearer "+os.Getenv("TOKEN"))\n\treq.Header.Set("Accept", "application/json")\n\n';
            return out + '\tres, err := http.DefaultClient.Do(req)\n\tif err != nil {\n\t\tpanic(err)\n\t}\n\tdefer res.Body.Close()\n\tdata, _ := io.ReadAll(res.Body)\n\tfmt.Println(res.Status, string(data))\n}';
        },
    };

    function currentBase() {
        var input = document.querySelector('[data-api-base]');
        return (input && input.value ? input.value : api.baseUrl).replace(/\/+$/, '');
    }

    var preferred = storage.get('vh-api-lang') || 'curl';

    function renderSample(id, lang) {
        var e = byId[id];
        var pre = document.querySelector('[data-api-sample="' + id + '"]');
        if (!e || !pre) return;
        pre.textContent = (samples[lang] || samples.curl)(e, currentBase());
        document.querySelectorAll('[data-api-langs="' + id + '"] [data-lang]').forEach(function (b) {
            b.setAttribute('aria-selected', b.dataset.lang === lang ? 'true' : 'false');
        });
    }

    function renderAllSamples() { Object.keys(byId).forEach(function (id) { renderSample(id, preferred); }); }

    document.querySelectorAll('[data-api-langs]').forEach(function (bar) {
        bar.addEventListener('click', function (ev) {
            var b = ev.target.closest('[data-lang]');
            if (!b) return;
            preferred = b.dataset.lang;
            storage.set('vh-api-lang', preferred);
            renderAllSamples();
        });
    });

    document.addEventListener('click', function (ev) {
        var copy = ev.target.closest('[data-api-copy]');
        if (!copy) return;
        var pre = copy.parentElement.querySelector('pre');
        if (navigator.clipboard && pre) {
            navigator.clipboard.writeText(pre.textContent).then(function () {
                var old = copy.textContent;
                copy.textContent = t.copied;
                setTimeout(function () { copy.textContent = old; }, 1200);
            });
        }
    });

    // --- filtr nawigacji ----------------------------------------------------

    var filter = document.querySelector('[data-api-filter]');
    if (filter) {
        filter.addEventListener('input', function () {
            var q = filter.value.trim().toLowerCase();
            document.querySelectorAll('[data-api-link]').forEach(function (a) {
                a.hidden = q !== '' && a.dataset.apiLink.indexOf(q) === -1;
            });
            document.querySelectorAll('.api-nav h4').forEach(function (h) {
                var any = false;
                for (var n = h.nextElementSibling; n && n.tagName !== 'H4'; n = n.nextElementSibling) { if (!n.hidden) any = true; }
                h.hidden = !any;
            });
        });
    }

    // --- widok: po prawej tylko wybrany element (jak w Postmanie) ------------

    var main = document.querySelector('[data-api-main]');
    var layout = document.querySelector('.api-layout');

    function showView(id) {
        if (!main) return;
        var target = id && id !== 'all' ? document.getElementById(id) : null;
        if (id && id !== 'all' && !target) { id = 'intro'; target = document.getElementById('intro'); }
        if (!id) { id = 'intro'; target = document.getElementById('intro'); }
        var all = id === 'all';
        var endpoint = target && target.hasAttribute('data-api-endpoint');
        main.classList.toggle('api-single', !all);
        main.querySelectorAll('[data-api-section]').forEach(function (s) {
            s.hidden = !(all || s === target || (s.id === 'console' && (endpoint || id === 'intro')));
        });
        main.querySelectorAll('[data-api-endpoint]').forEach(function (c) { c.hidden = !(all || c === target); });
        main.querySelectorAll('[data-api-group-head]').forEach(function (h) { h.hidden = !all; });
        document.querySelectorAll('[data-api-nav]').forEach(function (a) {
            a.setAttribute('aria-current', a.getAttribute('href') === '#' + id ? 'true' : 'false');
        });
        var top = layout ? layout.getBoundingClientRect().top + window.scrollY - 16 : 0;
        if (window.scrollY > top) window.scrollTo(0, top);
    }

    function currentView() { try { return decodeURIComponent(location.hash.slice(1)); } catch (e) { return ''; } }

    document.querySelectorAll('[data-api-nav]').forEach(function (a) {
        a.addEventListener('click', function (ev) {
            ev.preventDefault();
            var id = a.getAttribute('href').slice(1);
            if (history.pushState) history.pushState(null, '', '#' + id); else location.hash = id;
            showView(id);
        });
    });
    window.addEventListener('popstate', function () { showView(currentView()); });
    window.addEventListener('hashchange', function () { showView(currentView()); });
    showView(currentView());

    // --- konsola ------------------------------------------------------------

    var el = {
        token: document.querySelector('[data-api-token]'),
        remember: document.querySelector('[data-api-remember]'),
        base: document.querySelector('[data-api-base]'),
        method: document.querySelector('[data-api-method]'),
        path: document.querySelector('[data-api-path]'),
        body: document.querySelector('[data-api-body]'),
        send: document.querySelector('[data-api-send]'),
        meta: document.querySelector('[data-api-meta]'),
        output: document.querySelector('[data-api-output]'),
        console: document.getElementById('console'),
    };
    if (!el.send) { renderAllSamples(); return; }

    var saved = storage.get('vh-api-token');
    if (saved) { el.token.value = saved; el.remember.checked = true; }
    el.remember.addEventListener('change', function () {
        if (el.remember.checked) storage.set('vh-api-token', el.token.value); else storage.del('vh-api-token');
    });
    el.token.addEventListener('change', function () { if (el.remember.checked) storage.set('vh-api-token', el.token.value); });
    el.base.addEventListener('change', renderAllSamples);

    var last = { body: '', headers: '', curl: '' };
    var tab = 'body';

    function history() {
        try { return JSON.parse(storage.get('vh-api-history') || '[]'); } catch (e) { return []; }
    }

    function show(which) {
        tab = which;
        document.querySelectorAll('[data-api-rtab]').forEach(function (b) { b.setAttribute('aria-selected', b.dataset.apiRtab === which ? 'true' : 'false'); });
        if (which === 'history') {
            var items = history();
            el.output.textContent = items.length ? '' : t.emptyHistory;
            items.forEach(function (h, i) {
                var row = document.createElement('div');
                row.className = 'api-history-item';
                row.dataset.index = String(i);
                row.textContent = h.status + '  ' + h.method + ' ' + h.path + '  (' + h.ms + ' ms)';
                el.output.appendChild(row);
            });
            return;
        }
        el.output.textContent = last[which] || '';
    }

    document.querySelectorAll('[data-api-rtab]').forEach(function (b) {
        b.addEventListener('click', function () { show(b.dataset.apiRtab); });
    });
    el.output.addEventListener('click', function (ev) {
        var row = ev.target.closest('.api-history-item');
        if (tab !== 'history' || !row) return;
        var item = history()[Number(row.dataset.index)];
        if (item) { el.method.value = item.method; el.path.value = item.path; el.body.value = item.body || ''; show('body'); }
    });

    document.querySelectorAll('[data-api-try]').forEach(function (b) {
        b.addEventListener('click', function () {
            var e = byId[b.dataset.apiTry];
            if (!e) return;
            el.method.value = e.method;
            el.path.value = examplePath(e);
            el.body.value = hasBody(e) ? json(e.example_body) : '';
            el.console.scrollIntoView({ behavior: 'smooth', block: 'start' });
            el.token.value ? el.send.focus() : el.token.focus();
        });
    });

    el.send.addEventListener('click', function () {
        var method = el.method.value;
        var path = el.path.value.trim();
        if (path.charAt(0) !== '/') path = '/' + path;
        var url = el.base.value.replace(/\/+$/, '') + path;
        var headers = { Accept: 'application/json' };
        if (el.token.value.trim()) headers.Authorization = 'Bearer ' + el.token.value.trim();
        var init = { method: method, headers: headers, credentials: 'omit' };
        var raw = el.body.value.trim();
        if (raw && method !== 'GET') {
            try { JSON.parse(raw); } catch (e) { el.meta.hidden = false; el.meta.textContent = t.badJson; return; }
            headers['Content-Type'] = 'application/json';
            init.body = raw;
        }
        last.curl = 'curl -X ' + method + " '" + url + "'" + Object.keys(headers).map(function (k) {
            return " \\\n  -H '" + k + ': ' + (k === 'Authorization' ? 'Bearer $TOKEN' : headers[k]) + "'";
        }).join('') + (init.body ? " \\\n  -d '" + raw.replace(/'/g, "'\\''") + "'" : '');

        el.meta.hidden = false;
        el.meta.textContent = headers.Authorization ? t.sending : t.noToken;
        var started = performance.now();
        fetch(url, init).then(function (res) {
            return res.text().then(function (text) {
                var ms = Math.round(performance.now() - started);
                var pretty = text;
                try { pretty = json(JSON.parse(text)); } catch (e) { /* nie JSON */ }
                last.body = pretty;
                last.headers = '';
                res.headers.forEach(function (v, k) { last.headers += k + ': ' + v + '\n'; });
                el.meta.innerHTML = '';
                [['HTTP ' + res.status + ' ' + (res.statusText || ''), res.ok ? 'ok' : (res.status >= 500 ? 'critical' : 'warning')],
                    [t.time + ': ' + ms + ' ms', 'neutral'], [t.size + ': ' + new Blob([text]).size + ' B', 'neutral']].forEach(function (p) {
                    var span = document.createElement('span');
                    span.className = 'pill ' + p[1];
                    span.textContent = p[0];
                    el.meta.appendChild(span);
                });
                var items = history();
                items.unshift({ method: method, path: path, body: init.body || '', status: res.status, ms: ms });
                storage.set('vh-api-history', JSON.stringify(items.slice(0, 20)));
                show(tab === 'history' ? 'body' : tab);
            });
        }).catch(function (err) {
            el.meta.textContent = (t.networkError || '').replace(':error', err.message);
        });
    });

    renderAllSamples();
})();
