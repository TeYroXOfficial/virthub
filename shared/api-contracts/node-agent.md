# Kontrakt: control plane ↔ node agent

Źródłem prawdy dla kształtu ładunków są modele Pydantic w
`node-agent/agent/schemas.py`. Agent wystawia też żywą specyfikację OpenAPI pod
`/openapi.json` (i interfejs pod `/docs`), więc ten dokument opisuje **zasady**,
a nie każde pole z osobna.

## Uwierzytelnianie

Każde żądanie poza `GET /ping` musi nieść dwa nagłówki:

| Nagłówek | Zawartość |
|---|---|
| `X-VH-Timestamp` | uniksowy znacznik czasu (sekundy) |
| `X-VH-Signature` | `HMAC-SHA256(sekret, kanoniczny_string)` w hex |

Kanoniczny string:

```
{timestamp}\n{METODA}\n{ścieżka}\n{sha256(body)}
```

- `METODA` wielkimi literami (`POST`, `DELETE`).
- `ścieżka` bez hosta i bez query stringu (`/vm/abc-123/power`).
- `sha256(body)` liczony z **dokładnie tych bajtów**, które idą w żądaniu. Przy
  pustym ciele to `sha256("")`.
- Znacznik czasu starszy niż `VH_MAX_CLOCK_SKEW` (domyślnie 300 s) jest odrzucany.
  Wymaga to zsynchronizowanych zegarów — NTP na hypervisorze jest obowiązkowe.

Sekret jest **inny dla każdego kierunku**:

- panel → agent: `VH_AGENT_TOKEN` (w bazie: `hypervisors.agent_token`)
- agent → panel: `VH_CALLBACK_SECRET` (w bazie: `hypervisors.callback_secret`)

Dzięki temu przechwycony ruch w jedną stronę nie pozwala podszyć się w drugą.

## Model asynchroniczny

Wszystko, co zmienia stan maszyny, zwraca **202 Accepted** z `job_id`:

```json
{ "job_id": "32023b7c-f9d1-4976-88a0-7bea79ce0058", "status": "queued" }
```

Wynik dociera dwiema drogami, celowo redundantnymi:

1. **Callback** — agent wysyła `POST /api/internal/agent/job-result` do panelu,
   ponawiając co 5 sekund aż do skutku. Przerwa w łączności opóźnia aktualizację,
   ale jej nie gubi.
2. **Uzgadnianie** — panel dopytuje `GET /jobs/{job_id}` o zadania wiszące dłużej
   niż 45 sekund (`virthub:reconcile-jobs`).

Obie drogi trafiają do tego samego kodu stosującego wynik, a zadanie już
zamknięte jest ignorowane — powtórzony raport nie cofa stanu maszyny.

## Endpointy

| Metoda | Ścieżka | Charakter |
|---|---|---|
| `GET` | `/ping` | liveness, bez podpisu |
| `GET` | `/health` | heartbeat + wolne zasoby hosta |
| `POST` | `/vm` | utworzenie maszyny |
| `POST` | `/vm/{uuid}/power` | `start` / `stop` / `reboot` / `force-off` |
| `POST` | `/vm/{uuid}/rebuild` | ponowna instalacja systemu |
| `POST` | `/vm/{uuid}/resize` | zmiana vCPU / RAM / dysku |
| `DELETE` | `/vm/{uuid}` | usunięcie maszyny i jej dysku |
| `POST` | `/vm/{uuid}/snapshot` | kopia dysku |
| `POST` | `/vm/{uuid}/snapshot/restore` | przywrócenie kopii |
| `PUT` | `/vm/{uuid}/network` | adresacja i reguły firewalla |
| `GET` | `/vm/{uuid}/stats` | telemetria (synchronicznie) |
| `GET` | `/jobs/{job_id}` | stan zadania |

## Kody odpowiedzi i ich znaczenie dla panelu

| Kod | Znaczenie | Reakcja panelu |
|---|---|---|
| `202` | zadanie przyjęte | zapisz `agent_job_id`, czekaj na wynik |
| `401` | zły podpis lub rozjechane zegary | nie ponawiaj, zgłoś administratorowi |
| `404` | maszyna nie istnieje na tym węźle | nie ponawiaj |
| `409` | stan maszyny nie pozwala na operację | nie ponawiaj, pokaż komunikat klientowi |
| `422` | ładunek niezgodny z kontraktem | błąd programistyczny, nie ponawiaj |
| `5xx` / brak połączenia | awaria po stronie węzła | ponów z odstępem (10 s, 30 s) |

Rozróżnienie „ponawiać czy nie" jest zaimplementowane w
`AgentException::isRetryable()` — 4xx to stan, 5xx i brak łączności to awaria.

## Nazewnictwo zasobów na hypervisorze

Wartości wyprowadzane deterministycznie z `server_id`, żeby po odtworzeniu węzła
z backupu wszystko wskazywało na to samo:

| Zasób | Wzór | Przykład dla `server_id = 42` |
|---|---|---|
| domena libvirt | `virthub-{id}` | `virthub-42` |
| dysk | `{domena}.qcow2` | `virthub-42.qcow2` |
| interfejs tap | `vh{id}` | `vh42` |
| adres MAC | `52:54:00:xx:xx:xx` z `id` | `52:54:00:00:00:2a` |
| łańcuch nftables | `vm_{id}` | `vm_42` |

Nie zdajemy się na automatyczne `vnet0`/`vnet1` od libvirt — po restarcie hosta
numeracja potrafi się przesunąć i reguły firewalla trafiłyby w cudzą maszynę.
