# Webhooki

Administracja → System → **Webhooki**. Panel wysyła `POST` z JSON-em przy wybranych zdarzeniach
(lista w formularzu). Nieudane dostarczenia są ponawiane (1 min, 5 min, 30 min, 2 h, 6 h);
po 50 nieudanych dostarczeniach z rzędu webhook wyłącza się sam.

## Odbiorniki

| Odbiornik | Adres | Dodatkowo |
|---|---|---|
| VirtHub (JSON z podpisem) | Twój adres | sekret podpisu (pokazany raz), opcjonalnie token Bearer |
| Slack | adres „Incoming Webhook” aplikacji | — |
| Discord | Ustawienia kanału → Integracje → Webhooki → Kopiuj URL | — |
| Google Chat | Przestrzeń → Aplikacje i integracje → Webhooki | — |
| Telegram | `https://api.telegram.org/bot<TOKEN>/sendMessage` | ID czatu (`-100…` albo `@kanal`); bot musi być w czacie |
| Pushover | `https://api.pushover.net/1/messages.json` | klucz użytkownika i token aplikacji |

Slack, Discord, Google Chat, Telegram i Pushover dostają czytelną wiadomość, np.
`VirtHub · Serwer zbudowany i gotowy — vps1.example.com · 203.0.113.10`.

## Format VirtHub

```http
POST /hooks/virthub HTTP/1.1
Content-Type: application/json
User-Agent: VirtHub-Webhook/1
X-VirtHub-Event: server.built
X-VirtHub-Delivery: 7c1d…
X-VirtHub-Signature: t=1760000000,v1=5f2b…
Authorization: Bearer <token>          # tylko gdy ustawiony

{"id":"7c1d…","event":"server.built","created_at":"2026-10-15T12:00:00+00:00","data":{…}}
```

Podpis: `v1 = HMAC-SHA256(sekret, "<t>.<surowe body>")`. Odrzucaj wiadomości starsze niż
5 minut (`t`) i powtórzone `id` — chroni to przed powtórzeniem przechwyconego żądania.

### PHP

```php
$body = file_get_contents('php://input');
preg_match('/t=(\d+),v1=([a-f0-9]{64})/', $_SERVER['HTTP_X_VIRTHUB_SIGNATURE'] ?? '', $m);
$ok = $m && abs(time() - (int) $m[1]) < 300
    && hash_equals(hash_hmac('sha256', $m[1].'.'.$body, getenv('VIRTHUB_WEBHOOK_SECRET')), $m[2]);
if (! $ok) { http_response_code(401); exit; }
$event = json_decode($body, true);
// $event['event'], $event['data'] …
http_response_code(204);
```

### Node.js (Express)

```js
const crypto = require('crypto');
app.post('/hooks/virthub', express.raw({ type: 'application/json' }), (req, res) => {
  const [, t, sig] = /t=(\d+),v1=([a-f0-9]{64})/.exec(req.get('X-VirtHub-Signature') || '') || [];
  const expected = crypto.createHmac('sha256', process.env.VIRTHUB_WEBHOOK_SECRET).update(`${t}.${req.body}`).digest('hex');
  if (!t || Math.abs(Date.now() / 1000 - t) > 300 || !crypto.timingSafeEqual(Buffer.from(expected), Buffer.from(sig))) {
    return res.sendStatus(401);
  }
  const event = JSON.parse(req.body);
  res.sendStatus(204);
});
```

### Python (Flask)

```python
import hashlib, hmac, os, re, time
from flask import Flask, request, abort

app = Flask(__name__)

@app.post("/hooks/virthub")
def virthub():
    m = re.search(r"t=(\d+),v1=([a-f0-9]{64})", request.headers.get("X-VirtHub-Signature", ""))
    body = request.get_data()
    if not m or abs(time.time() - int(m[1])) > 300:
        abort(401)
    expected = hmac.new(os.environ["VIRTHUB_WEBHOOK_SECRET"].encode(), m[1].encode() + b"." + body, hashlib.sha256).hexdigest()
    if not hmac.compare_digest(expected, m[2]):
        abort(401)
    event = request.get_json()
    return "", 204
```
