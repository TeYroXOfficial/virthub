# Moduły billingowe VirtHub / VirtHub billing modules

Moduły dla **WHMCS**, **Blesta** i **Paymenter** (v1). Każdy korzysta z API
rozliczeniowego panelu (`/api/v1/billing`, token z zakresem `billing`) i potrafi:

- założyć, zawiesić, odwiesić, usunąć usługę i zmienić jej pakiet,
- pobrać listy pakietów, systemów i lokalizacji z panelu (konfiguracja produktu),
- dać klientowi sterowanie serwerem z panelu billingu: start, zamknięcie,
  restart, wyłączenie zasilania, konsola, reinstalacja systemu, reset hasła,
  stan / adresy IP / zasoby / transfer oraz logowanie do VirtHub (SSO),
- mówić po polsku albo po angielsku (także komunikaty błędów z panelu).

Paczki do pobrania i instrukcja: panel → Administracja → Billing → „WHMCS, Blesta, Paymenter”.

| System | Katalog w paczce |
|---|---|
| WHMCS | `modules/servers/virthub` |
| Blesta | `components/modules/virthub` |
| Paymenter | `extensions/Servers/VirtHub` (potem `composer dump-autoload`) |

## Dla deweloperów

Wspólny klient API (`shared/VirtHubClient.php`) i tłumaczenia (`shared/lang/*.php`)
są kopiowane do modułów skryptem:

```sh
php integrations/build.php          # kopiuje pliki (Blesta: generuje language/*/virthub.php)
php integrations/build.php --check  # błąd, gdy kopie są nieaktualne (sprawdza też test panelu)
php integrations/build.php --zip    # paczki w integrations/dist/
```

---

Modules for **WHMCS**, **Blesta** and **Paymenter** (v1) built on the panel's billing API
(`/api/v1/billing`, a token with the `billing` scope): provisioning, suspension, termination,
package changes and full server control from the billing client area (power, console,
reinstall, password reset, status and traffic, single sign-on). Download them from the
panel: Administration → Billing → “WHMCS, Blesta, Paymenter”.
