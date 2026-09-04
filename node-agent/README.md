# VirtHub Node Agent

Usługa działająca na hypervisorze. Jedyny komponent systemu, który dotyka
`libvirt`, dysków maszyn i reguł sieciowych hosta.

## Dlaczego osobny serwis, a nie część panelu

Panel jest w PHP i stoi zwykle na innej maszynie niż hypervisor. Agent musi mieć
niskopoziomowy dostęp do libvirt i jądra, działać jako proces systemd na hoście i
dokończyć rozpoczęte zadanie nawet wtedy, gdy panel jest akurat niedostępny.

## Tryby pracy

| `VH_AGENT_DRIVER` | Zastosowanie |
|---|---|
| `libvirt` | Produkcja — prawdziwe maszyny KVM |
| `mock` | Stacja developerska i CI — stan w pliku JSON, żadnych maszyn |

Tryb `mock` zwraca te same odpowiedzi HTTP co produkcyjny, więc panel da się
rozwijać i testować end-to-end na Windows czy macOS.

## Uruchomienie lokalne

```bash
python -m venv .venv
.venv/bin/pip install -r requirements.txt
cp .env.example .env        # ustaw VH_AGENT_DRIVER=mock i VH_AGENT_TOKEN
.venv/bin/uvicorn agent.main:app --host 127.0.0.1 --port 8899
```

Żywa dokumentacja API: `http://127.0.0.1:8899/docs`.

## Testy

```bash
.venv/bin/pytest -q
```

33 testy, wszystkie w trybie mock — nie wymagają hypervisora.

## Struktura

| Plik | Odpowiedzialność |
|---|---|
| `main.py` | Trasy HTTP, mapowanie akcji na kolejkę |
| `security.py` | Weryfikacja podpisu HMAC żądań z panelu |
| `jobs.py` | Lokalna kolejka SQLite, wznawianie po restarcie |
| `reporter.py` | Odsyłanie wyników do panelu z ponawianiem |
| `driver.py` | Sterownik libvirt oraz atrapa dla developmentu |
| `domain_xml.py` | Budowanie definicji domeny (czysta funkcja, testowalna) |
| `storage.py` | Interfejs storage + implementacja qcow2 |
| `network.py` | Nazwy interfejsów, anty-spoofing, reguły nftables |
| `cloudinit.py` | Nośnik NoCloud z konfiguracją pierwszego startu |

## Wymagania na hypervisorze

Poza pakietami Pythona agent wywołuje narzędzia systemowe:

- `qemu-img` — tworzenie i zmiana rozmiaru dysków
- `genisoimage` lub `xorriso` — budowanie nośnika cloud-init
- `nft` — reguły firewalla per maszyna
- działający `libvirtd` i mostek sieciowy (domyślnie `br0`)

Instalację całości robi playbook `infra/ansible/deploy-agent.yml`.

## Szablony systemów

Agent nie pobiera obrazów sam. Bazowe obrazy cloud (qcow2) kładzie się w
`VH_TEMPLATE_DIR`, a ich nazwy plików rejestruje w panelu jako szablony:

```bash
cd /var/lib/virthub/templates
curl -LO https://cloud-images.ubuntu.com/releases/24.04/release/ubuntu-24.04-server-cloudimg-amd64.img
mv ubuntu-24.04-server-cloudimg-amd64.img ubuntu-24.04.qcow2
```

Dysk nowej maszyny to cienka warstwa copy-on-write nad tym plikiem — dwadzieścia
maszyn z tej samej Ubuntu zajmuje miejsce jednego obrazu plus faktycznie zapisane
dane. **Obrazu bazowego nie wolno usunąć ani zmodyfikować**, dopóki istnieje
choć jedna maszyna, która go używa.
