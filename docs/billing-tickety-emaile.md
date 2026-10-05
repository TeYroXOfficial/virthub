# Billing, tickety i szablony e-mail — plan

Panel do tej pory tylko tworzył usługi (rozliczenia mógł robić zewnętrzny
WHMCS przez API `/api/v1/billing`). Ten dokument opisuje wbudowany system
rozliczeń, zgłoszeń i powiadomień e-mail. Zewnętrzny billing zostaje — wbudowany
włącza się przełącznikiem w Administracji i bez niego panel działa jak dotąd.

Realizacja w czterech etapach (osobne PR-y):

1. Szablony e-mail i powiadomienia.
2. Tickety.
3. Billing: katalog, zamówienia, portfel, faktury, cykle rozliczeń.
4. Bramki płatności: Stripe i PayPal.

---

## 1. Szablony e-mail

**Model** `email_templates` — nadpisania szablonów wbudowanych:
`key`, `locale` (pl/en), `subject`, `body` (Markdown), `enabled`.
Szablony domyślne są w kodzie (`App\Domain\Mail\EmailTemplates`), w bazie tylko
to, co administrator zmienił. „Przywróć domyślny” usuwa nadpisanie.

**Zmienne** `{{ user.name }}`, `{{ server.hostname }}`, `{{ invoice.total }}` …
— proste podstawianie (bez wykonywania Blade z bazy), wartości są escapowane,
treść renderowana jako Markdown w szablonie maila Laravela. Każdy szablon ma
listę dostępnych zmiennych pokazaną w edytorze.

**Zdarzenia** (klucz → kiedy):

| Klucz | Kiedy |
|---|---|
| `account.welcome` | konto założone przez administratora / rejestracja |
| `account.password_changed` | zmiana hasła do panelu |
| `server.created` | maszyna zainstalowana (adres IP, system, link) |
| `server.reinstalled` | reinstalacja zakończona |
| `server.password_reset` | nowe hasło roota ustawione (bez hasła w treści) |
| `server.suspended` / `server.unsuspended` | zawieszenie / odwieszenie |
| `server.terminated` | usunięcie maszyny |
| `app.created` | aplikacja zainstalowana |
| `app.reinstalled` | reinstalacja aplikacji zakończona |
| `ticket.opened`, `ticket.replied`, `ticket.closed` | zgłoszenia (klient) |
| `ticket.staff_new`, `ticket.staff_reply` | zgłoszenia (personel) |
| `invoice.created`, `invoice.paid`, `invoice.reminder`, `invoice.overdue` | faktury |
| `wallet.low_balance`, `wallet.topup` | portfel |
| `service.suspended_unpaid`, `service.terminated_unpaid` | brak płatności |

Wysyłka przez kolejkę (`TemplateMailer`), błąd SMTP nie przerywa operacji.
Administracja → System → Szablony e-mail: lista, edycja z podglądem, wysyłka
testowa, włącz/wyłącz, przywróć domyślny.

## 2. Tickety

**Tabele**: `ticket_departments` (nazwa, opis, aktywny, kolejność),
`tickets` (klient, dział, temat, status, priorytet, przypisany pracownik,
powiązana usługa — maszyna albo aplikacja, ostatnia odpowiedź),
`ticket_messages` (autor, treść, czy personel, notatka wewnętrzna),
`ticket_attachments` (prywatny dysk, limit rozmiaru i typów),
`ticket_canned_responses` (gotowe odpowiedzi personelu).

**Statusy**: `open` → `answered` (odpowiedział personel) → `customer_reply`
(odpowiedział klient) → `on_hold` → `closed`. Priorytety: niski, średni,
wysoki, pilny.

**Klient**: lista zgłoszeń, nowe zgłoszenie (dział, priorytet, usługa,
załączniki), wątek, odpowiedź, zamknięcie i ponowne otwarcie.

**Personel** (uprawnienie `admin.tickets`): kolejka z filtrami (status, dział,
priorytet, przypisane do mnie), przypisanie, zmiana statusu/priorytetu/działu,
odpowiedzi i notatki wewnętrzne (klient ich nie widzi), gotowe odpowiedzi,
link do usługi klienta. Automatyczne zamknięcie zgłoszeń bez odpowiedzi
klienta po N dniach. Powiadomienia e-mail z szablonów.

## 3. Billing

**Pieniądze** liczone w liczbach całkowitych z dokładnością 0,0001 (godzinne
stawki bywają ułamkami grosza); faktury zaokrąglane do 0,01. Jedna waluta
panelu (ustawienie).

**Ustawienia** (Administracja → Billing → Ustawienia): włączenie billingu,
waluta, VAT (procent, ceny netto/brutto), dane sprzedawcy na fakturze,
numeracja faktur, termin płatności, dni przypomnienia, dni do zawieszenia i
usunięcia, minimalne doładowanie, minimalne saldo dla usług godzinowych.

**Katalog**:
- `product_categories` — np. „VPS KVM”, „Kontenery”, „Serwery Minecraft”,
- `products` — kategoria, rodzaj (`vps` z pakietem VPS albo `app` z planem
  aplikacji i dozwolonymi szablonami), dozwolone lokalizacje (grupy węzłów),
  limit sztuk, opłata instalacyjna, aktywny,
- `product_prices` — cena dla okresu: dowolna liczba + jednostka (godziny,
  dni, tygodnie, miesiące, lata), np. 6 godzin, 3 dni, 2 tygodnie, 18 miesięcy;
  popularne okresy mają nazwy (`hourly`, `daily`, `monthly`, `quarterly`,
  `semiannually`, `annually`). Okres może być **jednorazowy** — płatność z góry
  za cały czas, potem usługa się kończy (np. darmowe 7 dni na test).

**Usługa rozliczana** `billing_services`: klient, produkt, cykl, cena,
status (`pending` → `active` → `suspended` → `terminated` / `cancelled`),
powiązana maszyna lub aplikacja, konfiguracja z zamówienia (system, nazwa,
lokalizacja…), `next_due_at`, anulowanie z końcem okresu.

**Portfel**: saldo konta + księga `wallet_transactions` (doładowanie, zapłata,
zwrot, korekta, opłata godzinowa) z saldem po operacji; zmiany salda pod
blokadą wiersza, nigdy poniżej zera przy płatnościach.

**Faktury** `invoices` + `invoice_items` + `payments`: numer, status
(`unpaid`, `paid`, `cancelled`, `refunded`), netto/VAT/brutto, termin,
pozycje z okresem rozliczeniowym. Rodzaje: za usługę i doładowanie portfela.
Wydruk HTML (do PDF z przeglądarki).

**Cykle**:
- *okresowe (miesiąc, kwartał, rok)* — po zamówieniu powstaje faktura na
  pierwszy okres (+ opłata instalacyjna). Jeśli saldo portfela wystarcza i
  klient tak wybrał, faktura opłaca się od razu z portfela; inaczej czeka na
  zapłatę portfelem albo Stripe/PayPal. Po zapłacie usługa jest tworzona.
  Fakturę odnowienia system tworzy N dni przed końcem okresu i opłaca z
  portfela automatycznie, jeśli są środki. Nieopłacona po terminie + karencja
  → zawieszenie, po kolejnych N dniach → usunięcie;
- *godzinowe i dzienne* (jak VirtFusion self service) — wymagają minimalnego
  salda; harmonogram co godzinę pobiera opłatę za każdą rozpoczętą
  godzinę/dobę z portfela. Przy niskim saldzie e-mail ostrzegawczy, przy
  saldzie poniżej zera zawieszenie, po N dniach usunięcie.

**Sklep (klient)**: kategorie → produkty z cenami cykli → konfiguracja
(lokalizacja, system / szablon aplikacji, nazwa) → podsumowanie → zapłata
portfelem albo faktura. Gdy billing jest włączony, klient zamawia przez sklep
(dotychczasowe zamawianie zostaje dla personelu). Klient widzi swoje usługi,
faktury, portfel z historią i może anulować usługę (z końcem okresu albo
od razu dla godzinowych).

**Administracja**: kategorie, produkty i ceny, usługi klientów (zawieś,
odwieś, zmień termin, anuluj), faktury (oznacz jako zapłaconą, anuluj, zwrot
do portfela), korekty salda portfela, ustawienia.

## 4. Stripe i PayPal

Bez bibliotek dostawców — klient HTTP Laravela.

**Stripe Checkout**: utworzenie sesji `mode=payment` (kwota faktury, waluta,
`metadata.invoice_id`, `client_reference_id`), przekierowanie klienta,
potwierdzenie z webhooka `checkout.session.completed`
(podpis `Stripe-Signature` HMAC-SHA256 z tolerancją czasu) — płatność
zaliczana tylko z webhooka, idempotentnie po identyfikatorze sesji.

**PayPal Orders v2**: token OAuth (client id + secret), zamówienie
`intent=CAPTURE` z `custom_id` = faktura, przekierowanie do akceptacji,
po powrocie przechwycenie (`capture`) i sprawdzenie kwoty/waluty; dodatkowo
webhook `PAYMENT.CAPTURE.COMPLETED` weryfikowany przez
`/v1/notifications/verify-webhook-signature`. Tryb sandbox/live.

Wspólne zasady: kwota i waluta płatności muszą zgadzać się z fakturą,
jedna płatność dostawcy zalicza się raz (unikalny identyfikator),
nadpłata trafia do portfela, sekrety szyfrowane kluczem panelu, każda
operacja w dzienniku zdarzeń.

## Uruchomienie (stan po wdrożeniu)

1. **Poczta** — Administracja → System → Poczta (SMTP), potem Szablony e-mail
   (każdy szablon ma wersję PL i EN, podgląd i wysyłkę testową).
2. **Zgłoszenia** — działają od razu; działy, gotowe odpowiedzi i automatyczne
   zamykanie: Administracja → Wsparcie → Działy i odpowiedzi. Personel potrzebuje
   uprawnienia „Zgłoszenia” (rola support ma je domyślnie).
3. **Billing**:
   - Billing → Ustawienia: waluta, VAT, dane sprzedawcy, terminy;
   - Billing → Produkty: kategorie i produkty z cenami;
   - na końcu przełącznik „Wbudowany billing” — od tej chwili klienci zamawiają
     przez Sklep, a personel nadal bezpośrednio.
4. **Bramki** — Billing → Bramki płatności (tylko administrator):
   - **Stripe**: klucz tajny i sekret webhooka; endpoint
     `https://PANEL/billing/webhooks/stripe` ze zdarzeniami
     `checkout.session.completed` i `checkout.session.async_payment_succeeded`;
   - **PayPal**: Client ID, Secret, tryb sandbox/produkcja i Webhook ID; endpoint
     `https://PANEL/billing/webhooks/paypal` ze zdarzeniami
     `CHECKOUT.ORDER.APPROVED` i `PAYMENT.CAPTURE.COMPLETED`.

Harmonogram (`schedule:run` z crona, instalator panelu go ustawia):

- `virthub:billing` co 5 minut;
- automatyczne zamykanie zgłoszeń co godzinę.

## Poza zakresem (kolejne kroki)

Kupony rabatowe, zmiana pakietu z dopłatą proporcjonalną, faktury PDF
generowane po stronie serwera, zwroty przez API bramek (na razie zwrot do
portfela albo ręcznie w panelu bramki), wiele walut.
