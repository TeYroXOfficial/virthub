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
| `POST` | `/vm/{uuid}/rebuild` | ponowna instalacja systemu (z `interfaces[]` i `nameservers[]`) |
| `POST` | `/vm/{uuid}/iso` | montowanie / wysunięcie płyty ISO (tylko KVM) |
| `POST` | `/vm/{uuid}/password` | nowe hasło roota w działającym systemie (`{"password": "…"}`) |
| `GET` | `/images/iso` | lista obrazów ISO na węźle |
| `POST` | `/images/iso` | pobranie obrazu ISO z URL (zadanie) |
| `DELETE` | `/images/iso/{name}` | usunięcie obrazu ISO |
| `POST` | `/vm/{uuid}/resize` | zmiana vCPU / RAM / dysku |
| `DELETE` | `/vm/{uuid}` | usunięcie maszyny i jej dysku |
| `POST` | `/vm/{uuid}/snapshot` | kopia dysku |
| `POST` | `/vm/{uuid}/snapshot/restore` | przywrócenie kopii |
| `PUT` | `/vm/{uuid}/network` | adresacja i reguły firewalla |
| `GET` | `/vm/{uuid}/stats` | telemetria (synchronicznie) |
| `GET` (WebSocket) | `/vm/{uuid}/console` | konsola: RFB dla KVM, terminal dla LXC |
| `GET` | `/system/update` | stan ostatniej aktualizacji węzła i commit agenta (`build`) |
| `POST` | `/system/update` | zlecenie aktualizacji (409, gdy węzeł nie ma usługi aktualizacji) |
| `GET` | `/jobs/{job_id}` | stan zadania |

### Etap zadania (`GET /jobs/{job_id}`)

Oprócz `status` zadanie ma pola `stage`, `progress` (0–100) i `detail`
(np. „412 MB z 1,2 GB · 18 MB/s"), aktualizowane przez driver w trakcie pracy: `prepare`, `download` (pobieranie obrazu LXC),
`stop`, `image`, `network`, `boot`, `disk`, `resources`. Panel pokazuje je na
ekranie postępu tworzenia / reinstalacji / zmiany pakietu. Starszy agent pól
nie zwraca — panel wraca wtedy do postępu szacowanego czasem.

### Obrazy ISO (`/images/iso`, `/vm/{uuid}/iso`)

`POST /images/iso` przyjmuje `{"name": "debian-13.iso", "url": "https://…", "sha256": "…"|null}`.
Nazwa: `^[a-z0-9][a-z0-9._-]{0,80}\.iso$`. Agent pobiera plik do
`VH_ISO_DIR` (domyślnie `/var/lib/virthub/isos`) jako `.part`, sprawdza sumę
SHA-256 i limit rozmiaru (`VH_ISO_MAX_GB`, domyślnie 20), dopiero potem
podmienia plik. Wynik zadania: `{"name", "size_bytes", "sha256"}`.

`POST /vm/{uuid}/iso` przyjmuje `{"iso": "debian-13.iso"|null, "boot": bool, "restart": bool}`.
`iso: null` wysuwa płytę. Płyta trafia do napędu SATA `sdb` (tylko do odczytu),
kolejność rozruchu jest ustawiana per urządzenie (`<boot order>`). Zmiana
definicji działa od następnego uruchomienia; `restart: true` robi to od razu
(destroy + start). Kontener odpowiada 409.

### Reset hasła (`/vm/{uuid}/password`)

Hasło: drukowalne ASCII bez spacji, 8–128 znaków. KVM ustawia je przez
qemu-guest-agent (`virDomainSetUserPassword`) — nowe maszyny dostają pakiet
`qemu-guest-agent` przez cloud-init. LXC: `incus exec … chpasswd`, hasło
przez stdin. Maszyna musi działać, inaczej zadanie kończy się błędem.

### Adres maszyny (`interfaces[]` w `POST /vm` i `PUT /vm/{uuid}/network`)

```json
{ "address": "203.0.113.14", "prefix": 24, "gateway": "203.0.113.1", "version": 4, "mode": "bridged" }
```

Adres za NAT-em węzła:

```json
{
  "address": "10.10.0.5", "prefix": 24, "gateway": "10.10.0.1", "version": 4, "mode": "nat",
  "nat": { "network": "10.10.0.0/24", "snat_address": "198.51.100.7", "port_from": 10100, "port_to": 10119 }
}
```

- `mode: bridged` — maszyna na mostku `VH_BRIDGE` (z kartą fizyczną),
  `mode: nat` — na mostku `VH_NAT_BRIDGE` (domyślnie `vhnat0`). Wszystkie adresy
  jednej maszyny są jednego rodzaju.
- `gateway` przy NAT to adres, który agent nadaje mostkowi NAT; musi leżeć w `nat.network`.
- `snat_address` — publiczny adres wyjścia; `null` = maskarada na interfejsie wyjściowym.
- `port_from`–`port_to` (tylko IPv4): `port_from` → port 22 maszyny, reszta 1:1;
  `null` = bez przekierowań.
- Adresy są walidowane jako IP — trafiają do reguł nftables.

### Zapora (`PUT /vm/{uuid}/network`)

```json
{
  "interfaces": [ … ],
  "firewall": [
    { "action": "drop", "direction": "in", "protocol": "any", "source": "192.0.2.0/24" },
    { "action": "accept", "direction": "in", "protocol": "tcp", "port_from": 22 }
  ],
  "policy": { "enabled": true, "inbound": "drop", "outbound": "accept" }
}
```

- Reguły są stosowane w podanej kolejności (panel wysyła najpierw reguły
  administratora); `source` to adres drugiej strony — nadawca dla `in`,
  odbiorca dla `out`; walidowany jako IP/CIDR.
- `policy` brak (starszy panel) = reguły bez domyślnej blokady.
  `enabled: false` = tylko anty-spoofing.

### Konsola (`/vm/{uuid}/console`)

WebSocket podpisany jak każde żądanie (`GET`, pusta treść, nagłówki `X-VH-*`
w handshake'u); bez poprawnego podpisu połączenie jest odrzucane przed
otwarciem. Łączy się z nim wyłącznie przekaźnik konsoli panelu.

- **KVM** — surowy strumień RFB z gniazda VNC QEMU (127.0.0.1). Podprotokół
  `binary` jest potwierdzany, jeśli klient o niego poprosi (noVNC). Hasło VNC
  obsługuje klient.
- **LXC** — `incus exec -t … /bin/login` na pseudoterminalu. Ramki binarne to
  dane w obie strony; ramki tekstowe od klienta to sterowanie w JSON:
  `{"type": "resize", "cols": 120, "rows": 40}`.

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
| przekierowania NAT | plik `nat/{id}.json` obok bazy stanu, mapa `fwd4` w `inet virthub_nat` | `nat/42.json` |

Nie zdajemy się na automatyczne `vnet0`/`vnet1` od libvirt — po restarcie hosta
numeracja potrafi się przesunąć i reguły firewalla trafiłyby w cudzą maszynę.
