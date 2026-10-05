<?php

namespace App\Domain\Mail;

use App\Models\EmailTemplate;

/**
 * Szablony e-maili wysyłanych przez panel. Domyślne treści są tutaj, w bazie
 * (email_templates) tylko nadpisania administratora — „przywróć domyślny”
 * usuwa nadpisanie.
 *
 * Treść to Markdown ze zmiennymi {{ nazwa.pole }}. Zmiennych nie wykonujemy
 * jako kodu: podstawiamy wartości (escapowane), więc szablon edytowany w
 * panelu nie może uruchomić niczego na serwerze.
 */
final class EmailTemplates
{
    public const LOCALES = ['pl', 'en'];

    /** Zmienne dostępne w każdym szablonie. */
    public const COMMON_VARS = ['brand', 'panel_url', 'user.name', 'user.email'];

    /**
     * @return array<string, array{group: string, name: array{pl: string, en: string}, vars: list<string>, pl: array{subject: string, body: string}, en: array{subject: string, body: string}}>
     */
    public static function definitions(): array
    {
        return [
            // --- konto -----------------------------------------------------------------
            'account.welcome' => [
                'group' => 'account',
                'name' => ['pl' => 'Powitanie (nowe konto)', 'en' => 'Welcome (new account)'],
                'vars' => ['login_url'],
                'pl' => [
                    'subject' => 'Witaj w {{ brand }}',
                    'body' => "Cześć {{ user.name }}!\n\nTwoje konto w panelu **{{ brand }}** jest gotowe. Zaloguj się adresem e-mail **{{ user.email }}**:\n\n[Zaloguj się]({{ login_url }})\n\nJeśli nie znasz hasła, użyj na stronie logowania opcji „Nie pamiętasz hasła?”.",
                ],
                'en' => [
                    'subject' => 'Welcome to {{ brand }}',
                    'body' => "Hi {{ user.name }}!\n\nYour **{{ brand }}** panel account is ready. Sign in with **{{ user.email }}**:\n\n[Sign in]({{ login_url }})\n\nIf you don't know your password, use “Forgot your password?” on the sign-in page.",
                ],
            ],
            'account.password_changed' => [
                'group' => 'account',
                'name' => ['pl' => 'Zmiana hasła do panelu', 'en' => 'Panel password changed'],
                'vars' => ['ip'],
                'pl' => [
                    'subject' => 'Hasło do panelu {{ brand }} zostało zmienione',
                    'body' => "Cześć {{ user.name }},\n\nhasło do Twojego konta zostało właśnie zmienione (adres IP: {{ ip }}).\n\nJeśli to nie Ty — natychmiast ustaw nowe hasło przez „Nie pamiętasz hasła?” i skontaktuj się z nami.",
                ],
                'en' => [
                    'subject' => 'Your {{ brand }} password was changed',
                    'body' => "Hi {{ user.name }},\n\nthe password of your account was just changed (IP address: {{ ip }}).\n\nIf this wasn't you, set a new password right away with “Forgot your password?” and contact us.",
                ],
            ],

            // --- maszyny -------------------------------------------------------------
            'server.created' => [
                'group' => 'server',
                'name' => ['pl' => 'Maszyna gotowa', 'en' => 'Machine ready'],
                'vars' => ['server.hostname', 'server.ip', 'server.os', 'server.vcpu', 'server.ram', 'server.disk', 'server.url'],
                'pl' => [
                    'subject' => 'Twoja maszyna {{ server.hostname }} jest gotowa',
                    'body' => "Cześć {{ user.name }},\n\nmaszyna **{{ server.hostname }}** została zainstalowana i działa.\n\n- Adres IP: **{{ server.ip }}**\n- System: {{ server.os }}\n- Zasoby: {{ server.vcpu }} vCPU, {{ server.ram }} RAM, {{ server.disk }} dysku\n\nHasło roota i konsolę znajdziesz w panelu:\n\n[Otwórz maszynę]({{ server.url }})",
                ],
                'en' => [
                    'subject' => 'Your machine {{ server.hostname }} is ready',
                    'body' => "Hi {{ user.name }},\n\nthe machine **{{ server.hostname }}** has been installed and is running.\n\n- IP address: **{{ server.ip }}**\n- OS: {{ server.os }}\n- Resources: {{ server.vcpu }} vCPU, {{ server.ram }} RAM, {{ server.disk }} disk\n\nThe root password and console are in the panel:\n\n[Open the machine]({{ server.url }})",
                ],
            ],
            'server.reinstalled' => [
                'group' => 'server',
                'name' => ['pl' => 'Reinstalacja zakończona', 'en' => 'Reinstall finished'],
                'vars' => ['server.hostname', 'server.ip', 'server.os', 'server.url'],
                'pl' => [
                    'subject' => 'Reinstalacja {{ server.hostname }} zakończona',
                    'body' => "Cześć {{ user.name }},\n\nsystem maszyny **{{ server.hostname }}** ({{ server.ip }}) został zainstalowany od nowa: {{ server.os }}.\n\n[Otwórz maszynę]({{ server.url }})",
                ],
                'en' => [
                    'subject' => 'Reinstall of {{ server.hostname }} finished',
                    'body' => "Hi {{ user.name }},\n\nthe machine **{{ server.hostname }}** ({{ server.ip }}) has been reinstalled with {{ server.os }}.\n\n[Open the machine]({{ server.url }})",
                ],
            ],
            'server.password_reset' => [
                'group' => 'server',
                'name' => ['pl' => 'Nowe hasło roota', 'en' => 'New root password'],
                'vars' => ['server.hostname', 'server.ip', 'server.url'],
                'pl' => [
                    'subject' => 'Nowe hasło do {{ server.hostname }}',
                    'body' => "Cześć {{ user.name }},\n\nna maszynie **{{ server.hostname }}** ({{ server.ip }}) ustawiono nowe hasło. Ze względów bezpieczeństwa nie wysyłamy go mailem — zobaczysz je w panelu.\n\n[Otwórz maszynę]({{ server.url }})",
                ],
                'en' => [
                    'subject' => 'New password for {{ server.hostname }}',
                    'body' => "Hi {{ user.name }},\n\na new password was set on **{{ server.hostname }}** ({{ server.ip }}). For security we don't send it by e-mail — you'll find it in the panel.\n\n[Open the machine]({{ server.url }})",
                ],
            ],
            'server.suspended' => [
                'group' => 'server',
                'name' => ['pl' => 'Maszyna zawieszona', 'en' => 'Machine suspended'],
                'vars' => ['server.hostname', 'reason', 'server.url'],
                'pl' => [
                    'subject' => 'Maszyna {{ server.hostname }} została zawieszona',
                    'body' => "Cześć {{ user.name }},\n\nmaszyna **{{ server.hostname }}** została zawieszona i zatrzymana.\n\nPowód: {{ reason }}\n\nW razie pytań napisz do nas z panelu (Pomoc → Zgłoszenia).",
                ],
                'en' => [
                    'subject' => 'Machine {{ server.hostname }} was suspended',
                    'body' => "Hi {{ user.name }},\n\nthe machine **{{ server.hostname }}** has been suspended and stopped.\n\nReason: {{ reason }}\n\nIf you have questions, contact us from the panel (Support → Tickets).",
                ],
            ],
            'server.unsuspended' => [
                'group' => 'server',
                'name' => ['pl' => 'Maszyna odwieszona', 'en' => 'Machine unsuspended'],
                'vars' => ['server.hostname', 'server.url'],
                'pl' => [
                    'subject' => 'Maszyna {{ server.hostname }} znów działa',
                    'body' => "Cześć {{ user.name }},\n\nzawieszenie maszyny **{{ server.hostname }}** zostało zdjęte.\n\n[Otwórz maszynę]({{ server.url }})",
                ],
                'en' => [
                    'subject' => 'Machine {{ server.hostname }} is back',
                    'body' => "Hi {{ user.name }},\n\nthe suspension of **{{ server.hostname }}** has been lifted.\n\n[Open the machine]({{ server.url }})",
                ],
            ],
            'server.terminated' => [
                'group' => 'server',
                'name' => ['pl' => 'Maszyna usunięta', 'en' => 'Machine deleted'],
                'vars' => ['server.hostname', 'server.ip'],
                'pl' => [
                    'subject' => 'Maszyna {{ server.hostname }} została usunięta',
                    'body' => "Cześć {{ user.name }},\n\nmaszyna **{{ server.hostname }}** ({{ server.ip }}) została usunięta razem z dyskiem. Adres IP wrócił do puli.",
                ],
                'en' => [
                    'subject' => 'Machine {{ server.hostname }} was deleted',
                    'body' => "Hi {{ user.name }},\n\nthe machine **{{ server.hostname }}** ({{ server.ip }}) has been deleted together with its disk. The IP address went back to the pool.",
                ],
            ],

            // --- aplikacje --------------------------------------------------------------
            'app.created' => [
                'group' => 'app',
                'name' => ['pl' => 'Aplikacja gotowa', 'en' => 'Application ready'],
                'vars' => ['app.name', 'app.address', 'app.template', 'app.url'],
                'pl' => [
                    'subject' => 'Twój serwer {{ app.name }} jest gotowy',
                    'body' => "Cześć {{ user.name }},\n\n**{{ app.name }}** ({{ app.template }}) jest zainstalowany.\n\n- Adres: **{{ app.address }}**\n\nKonsolę, pliki i SFTP znajdziesz w panelu:\n\n[Otwórz serwer]({{ app.url }})",
                ],
                'en' => [
                    'subject' => 'Your server {{ app.name }} is ready',
                    'body' => "Hi {{ user.name }},\n\n**{{ app.name }}** ({{ app.template }}) has been installed.\n\n- Address: **{{ app.address }}**\n\nConsole, files and SFTP are in the panel:\n\n[Open the server]({{ app.url }})",
                ],
            ],
            'app.reinstalled' => [
                'group' => 'app',
                'name' => ['pl' => 'Reinstalacja aplikacji', 'en' => 'Application reinstalled'],
                'vars' => ['app.name', 'app.address', 'app.url'],
                'pl' => [
                    'subject' => 'Reinstalacja {{ app.name }} zakończona',
                    'body' => "Cześć {{ user.name }},\n\nreinstalacja **{{ app.name }}** ({{ app.address }}) zakończyła się.\n\n[Otwórz serwer]({{ app.url }})",
                ],
                'en' => [
                    'subject' => 'Reinstall of {{ app.name }} finished',
                    'body' => "Hi {{ user.name }},\n\nthe reinstall of **{{ app.name }}** ({{ app.address }}) has finished.\n\n[Open the server]({{ app.url }})",
                ],
            ],

            // --- tickety ----------------------------------------------------------------
            'ticket.opened' => [
                'group' => 'ticket',
                'name' => ['pl' => 'Zgłoszenie przyjęte (klient)', 'en' => 'Ticket received (customer)'],
                'vars' => ['ticket.number', 'ticket.subject', 'ticket.department', 'ticket.url'],
                'pl' => [
                    'subject' => '[#{{ ticket.number }}] {{ ticket.subject }}',
                    'body' => "Cześć {{ user.name }},\n\notrzymaliśmy Twoje zgłoszenie **#{{ ticket.number }}** ({{ ticket.department }}). Odpowiemy najszybciej, jak to możliwe.\n\n[Zobacz zgłoszenie]({{ ticket.url }})",
                ],
                'en' => [
                    'subject' => '[#{{ ticket.number }}] {{ ticket.subject }}',
                    'body' => "Hi {{ user.name }},\n\nwe've received your ticket **#{{ ticket.number }}** ({{ ticket.department }}) and will reply as soon as possible.\n\n[View the ticket]({{ ticket.url }})",
                ],
            ],
            'ticket.replied' => [
                'group' => 'ticket',
                'name' => ['pl' => 'Odpowiedź w zgłoszeniu (klient)', 'en' => 'Ticket reply (customer)'],
                'vars' => ['ticket.number', 'ticket.subject', 'ticket.url', 'message', 'staff.name'],
                'pl' => [
                    'subject' => '[#{{ ticket.number }}] Odpowiedź: {{ ticket.subject }}',
                    'body' => "Cześć {{ user.name }},\n\n{{ staff.name }} odpowiedział(a) w zgłoszeniu **#{{ ticket.number }}**:\n\n{{ message }}\n\n[Odpowiedz w panelu]({{ ticket.url }})",
                ],
                'en' => [
                    'subject' => '[#{{ ticket.number }}] Reply: {{ ticket.subject }}',
                    'body' => "Hi {{ user.name }},\n\n{{ staff.name }} replied to ticket **#{{ ticket.number }}**:\n\n{{ message }}\n\n[Reply in the panel]({{ ticket.url }})",
                ],
            ],
            'ticket.closed' => [
                'group' => 'ticket',
                'name' => ['pl' => 'Zgłoszenie zamknięte (klient)', 'en' => 'Ticket closed (customer)'],
                'vars' => ['ticket.number', 'ticket.subject', 'ticket.url'],
                'pl' => [
                    'subject' => '[#{{ ticket.number }}] Zamknięte: {{ ticket.subject }}',
                    'body' => "Cześć {{ user.name }},\n\nzgłoszenie **#{{ ticket.number }}** zostało zamknięte. Jeśli problem wróci, możesz je otworzyć ponownie, odpowiadając w panelu.\n\n[Zobacz zgłoszenie]({{ ticket.url }})",
                ],
                'en' => [
                    'subject' => '[#{{ ticket.number }}] Closed: {{ ticket.subject }}',
                    'body' => "Hi {{ user.name }},\n\nticket **#{{ ticket.number }}** has been closed. If the problem comes back, you can reopen it by replying in the panel.\n\n[View the ticket]({{ ticket.url }})",
                ],
            ],
            'ticket.staff_new' => [
                'group' => 'ticket',
                'name' => ['pl' => 'Nowe zgłoszenie (personel)', 'en' => 'New ticket (staff)'],
                'vars' => ['ticket.number', 'ticket.subject', 'ticket.department', 'ticket.priority', 'customer.email', 'message', 'ticket.admin_url'],
                'pl' => [
                    'subject' => '[#{{ ticket.number }}] Nowe zgłoszenie: {{ ticket.subject }}',
                    'body' => "Nowe zgłoszenie od **{{ customer.email }}** — dział {{ ticket.department }}, priorytet {{ ticket.priority }}.\n\n{{ message }}\n\n[Otwórz w panelu]({{ ticket.admin_url }})",
                ],
                'en' => [
                    'subject' => '[#{{ ticket.number }}] New ticket: {{ ticket.subject }}',
                    'body' => "New ticket from **{{ customer.email }}** — {{ ticket.department }} department, {{ ticket.priority }} priority.\n\n{{ message }}\n\n[Open in the panel]({{ ticket.admin_url }})",
                ],
            ],
            'ticket.staff_reply' => [
                'group' => 'ticket',
                'name' => ['pl' => 'Odpowiedź klienta (personel)', 'en' => 'Customer reply (staff)'],
                'vars' => ['ticket.number', 'ticket.subject', 'customer.email', 'message', 'ticket.admin_url'],
                'pl' => [
                    'subject' => '[#{{ ticket.number }}] Odpowiedź klienta: {{ ticket.subject }}',
                    'body' => "**{{ customer.email }}** odpowiedział(a) w zgłoszeniu #{{ ticket.number }}:\n\n{{ message }}\n\n[Otwórz w panelu]({{ ticket.admin_url }})",
                ],
                'en' => [
                    'subject' => '[#{{ ticket.number }}] Customer reply: {{ ticket.subject }}',
                    'body' => "**{{ customer.email }}** replied to ticket #{{ ticket.number }}:\n\n{{ message }}\n\n[Open in the panel]({{ ticket.admin_url }})",
                ],
            ],

            // --- billing --------------------------------------------------------------------
            'invoice.created' => [
                'group' => 'billing',
                'name' => ['pl' => 'Nowa faktura', 'en' => 'New invoice'],
                'vars' => ['invoice.number', 'invoice.total', 'invoice.due_at', 'invoice.url', 'wallet.balance'],
                'pl' => [
                    'subject' => 'Faktura {{ invoice.number }} do zapłaty',
                    'body' => "Cześć {{ user.name }},\n\nwystawiliśmy fakturę **{{ invoice.number }}** na **{{ invoice.total }}**, termin płatności: {{ invoice.due_at }}.\n\nMożesz ją opłacić z portfela (saldo: {{ wallet.balance }}) albo kartą / PayPalem:\n\n[Zapłać fakturę]({{ invoice.url }})",
                ],
                'en' => [
                    'subject' => 'Invoice {{ invoice.number }} is due',
                    'body' => "Hi {{ user.name }},\n\nwe've issued invoice **{{ invoice.number }}** for **{{ invoice.total }}**, due {{ invoice.due_at }}.\n\nPay it from your wallet (balance: {{ wallet.balance }}) or by card / PayPal:\n\n[Pay the invoice]({{ invoice.url }})",
                ],
            ],
            'invoice.paid' => [
                'group' => 'billing',
                'name' => ['pl' => 'Faktura opłacona', 'en' => 'Invoice paid'],
                'vars' => ['invoice.number', 'invoice.total', 'payment.method', 'invoice.url'],
                'pl' => [
                    'subject' => 'Dziękujemy za płatność — faktura {{ invoice.number }}',
                    'body' => "Cześć {{ user.name }},\n\notrzymaliśmy płatność **{{ invoice.total }}** ({{ payment.method }}) za fakturę **{{ invoice.number }}**.\n\n[Zobacz fakturę]({{ invoice.url }})",
                ],
                'en' => [
                    'subject' => 'Thank you for your payment — invoice {{ invoice.number }}',
                    'body' => "Hi {{ user.name }},\n\nwe've received **{{ invoice.total }}** ({{ payment.method }}) for invoice **{{ invoice.number }}**.\n\n[View the invoice]({{ invoice.url }})",
                ],
            ],
            'invoice.reminder' => [
                'group' => 'billing',
                'name' => ['pl' => 'Przypomnienie o fakturze', 'en' => 'Invoice reminder'],
                'vars' => ['invoice.number', 'invoice.total', 'invoice.due_at', 'invoice.url'],
                'pl' => [
                    'subject' => 'Przypomnienie: faktura {{ invoice.number }}',
                    'body' => "Cześć {{ user.name }},\n\nprzypominamy o fakturze **{{ invoice.number }}** na **{{ invoice.total }}** z terminem {{ invoice.due_at }}. Brak płatności spowoduje zawieszenie usługi.\n\n[Zapłać fakturę]({{ invoice.url }})",
                ],
                'en' => [
                    'subject' => 'Reminder: invoice {{ invoice.number }}',
                    'body' => "Hi {{ user.name }},\n\na reminder about invoice **{{ invoice.number }}** for **{{ invoice.total }}**, due {{ invoice.due_at }}. Without payment the service will be suspended.\n\n[Pay the invoice]({{ invoice.url }})",
                ],
            ],
            'invoice.overdue' => [
                'group' => 'billing',
                'name' => ['pl' => 'Faktura po terminie', 'en' => 'Invoice overdue'],
                'vars' => ['invoice.number', 'invoice.total', 'invoice.url'],
                'pl' => [
                    'subject' => 'Faktura {{ invoice.number }} jest po terminie',
                    'body' => "Cześć {{ user.name }},\n\nfaktura **{{ invoice.number }}** na **{{ invoice.total }}** nie została opłacona w terminie. Usługa zostanie zawieszona — po zapłacie wznowimy ją automatycznie.\n\n[Zapłać fakturę]({{ invoice.url }})",
                ],
                'en' => [
                    'subject' => 'Invoice {{ invoice.number }} is overdue',
                    'body' => "Hi {{ user.name }},\n\ninvoice **{{ invoice.number }}** for **{{ invoice.total }}** wasn't paid on time. The service will be suspended — we'll resume it automatically after payment.\n\n[Pay the invoice]({{ invoice.url }})",
                ],
            ],
            'wallet.topup' => [
                'group' => 'billing',
                'name' => ['pl' => 'Doładowanie portfela', 'en' => 'Wallet top-up'],
                'vars' => ['amount', 'wallet.balance', 'wallet.url'],
                'pl' => [
                    'subject' => 'Portfel doładowany: {{ amount }}',
                    'body' => "Cześć {{ user.name }},\n\nTwój portfel został doładowany kwotą **{{ amount }}**. Aktualne saldo: **{{ wallet.balance }}**.\n\n[Zobacz portfel]({{ wallet.url }})",
                ],
                'en' => [
                    'subject' => 'Wallet topped up: {{ amount }}',
                    'body' => "Hi {{ user.name }},\n\nyour wallet was topped up with **{{ amount }}**. Current balance: **{{ wallet.balance }}**.\n\n[View the wallet]({{ wallet.url }})",
                ],
            ],
            'wallet.low_balance' => [
                'group' => 'billing',
                'name' => ['pl' => 'Niskie saldo portfela', 'en' => 'Low wallet balance'],
                'vars' => ['wallet.balance', 'hours_left', 'wallet.url'],
                'pl' => [
                    'subject' => 'Niskie saldo portfela — {{ wallet.balance }}',
                    'body' => "Cześć {{ user.name }},\n\nsaldo Twojego portfela (**{{ wallet.balance }}**) wystarczy na około **{{ hours_left }} h** działania usług rozliczanych godzinowo. Po wyczerpaniu środków usługi zostaną zawieszone.\n\n[Doładuj portfel]({{ wallet.url }})",
                ],
                'en' => [
                    'subject' => 'Low wallet balance — {{ wallet.balance }}',
                    'body' => "Hi {{ user.name }},\n\nyour wallet balance (**{{ wallet.balance }}**) covers about **{{ hours_left }} h** of your hourly services. When it runs out the services will be suspended.\n\n[Top up the wallet]({{ wallet.url }})",
                ],
            ],
            'service.suspended_unpaid' => [
                'group' => 'billing',
                'name' => ['pl' => 'Usługa zawieszona (brak płatności)', 'en' => 'Service suspended (unpaid)'],
                'vars' => ['service.name', 'terminate_at', 'billing.url'],
                'pl' => [
                    'subject' => 'Usługa {{ service.name }} zawieszona — brak płatności',
                    'body' => "Cześć {{ user.name }},\n\nusługa **{{ service.name }}** została zawieszona z powodu braku płatności. Jeśli nie zostanie opłacona do {{ terminate_at }}, zostanie usunięta razem z danymi.\n\n[Przejdź do płatności]({{ billing.url }})",
                ],
                'en' => [
                    'subject' => 'Service {{ service.name }} suspended — unpaid',
                    'body' => "Hi {{ user.name }},\n\nthe service **{{ service.name }}** has been suspended for non-payment. If it isn't paid by {{ terminate_at }}, it will be deleted together with its data.\n\n[Go to billing]({{ billing.url }})",
                ],
            ],
            'service.keepalive' => [
                'group' => 'billing',
                'name' => ['pl' => 'Potwierdź aktywność usługi', 'en' => 'Confirm service activity'],
                'vars' => ['service.name', 'service.url', 'keepalive.until'],
                'pl' => [
                    'subject' => 'Przedłuż usługę {{ service.name }}',
                    'body' => "Cześć {{ user.name }},\n\nusługa **{{ service.name }}** wymaga potwierdzenia, że nadal z niej korzystasz. Kliknij „Przedłuż” w panelu do {{ keepalive.until }} — inaczej zostanie zawieszona.\n\n[Przedłuż usługę]({{ service.url }})",
                ],
                'en' => [
                    'subject' => 'Extend your service {{ service.name }}',
                    'body' => "Hi {{ user.name }},\n\nthe service **{{ service.name }}** needs you to confirm you're still using it. Click “Extend” in the panel by {{ keepalive.until }} — otherwise it will be suspended.\n\n[Extend the service]({{ service.url }})",
                ],
            ],
            'service.suspended_inactive' => [
                'group' => 'billing',
                'name' => ['pl' => 'Usługa zawieszona (brak aktywności)', 'en' => 'Service suspended (inactivity)'],
                'vars' => ['service.name', 'service.url', 'terminate_at'],
                'pl' => [
                    'subject' => 'Usługa {{ service.name }} zawieszona — brak potwierdzenia aktywności',
                    'body' => "Cześć {{ user.name }},\n\nusługa **{{ service.name }}** została zawieszona, bo nie potwierdzono aktywności na czas. Kliknij „Przedłuż” w panelu, a wróci od razu. Bez tego {{ terminate_at }} zostanie usunięta razem z danymi.\n\n[Przywróć usługę]({{ service.url }})",
                ],
                'en' => [
                    'subject' => 'Service {{ service.name }} suspended — activity not confirmed',
                    'body' => "Hi {{ user.name }},\n\nthe service **{{ service.name }}** has been suspended because activity wasn't confirmed in time. Click “Extend” in the panel and it will come back right away. Otherwise it will be deleted with its data on {{ terminate_at }}.\n\n[Restore the service]({{ service.url }})",
                ],
            ],
            'service.terminated_unpaid' => [
                'group' => 'billing',
                'name' => ['pl' => 'Usługa usunięta (brak płatności)', 'en' => 'Service terminated (unpaid)'],
                'vars' => ['service.name'],
                'pl' => [
                    'subject' => 'Usługa {{ service.name }} została usunięta',
                    'body' => "Cześć {{ user.name }},\n\nusługa **{{ service.name }}** została usunięta z powodu braku płatności.",
                ],
                'en' => [
                    'subject' => 'Service {{ service.name }} was terminated',
                    'body' => "Hi {{ user.name }},\n\nthe service **{{ service.name }}** has been terminated for non-payment.",
                ],
            ],
        ];
    }

    public static function exists(string $key): bool
    {
        return isset(self::definitions()[$key]);
    }

    /** @return array{subject: string, body: string, enabled: bool, custom: bool} */
    public static function resolve(string $key, string $locale): array
    {
        $definition = self::definitions()[$key] ?? throw new \InvalidArgumentException("Nieznany szablon: {$key}");
        $locale = in_array($locale, self::LOCALES, true) ? $locale : 'pl';

        $override = EmailTemplate::query()->where('key', $key)->where('locale', $locale)->first();

        return [
            'subject' => $override?->subject ?? $definition[$locale]['subject'],
            'body' => $override?->body ?? $definition[$locale]['body'],
            'enabled' => $override?->enabled ?? true,
            'custom' => $override !== null,
        ];
    }

    /** @return list<string> */
    public static function variables(string $key): array
    {
        return [...self::COMMON_VARS, ...(self::definitions()[$key]['vars'] ?? [])];
    }

    /** Przykładowe dane do podglądu i wysyłki testowej. */
    public static function sample(): array
    {
        return [
            'login_url' => url('/login'), 'ip' => '203.0.113.7', 'reason' => 'Przykładowy powód', 'message' => "Przykładowa treść wiadomości.\nDruga linia.",
            'amount' => '50,00 PLN', 'hours_left' => '12', 'terminate_at' => now()->addDays(7)->format('d.m.Y'),
            'server' => ['hostname' => 'vps1.example.com', 'ip' => '203.0.113.10', 'os' => 'Ubuntu 24.04 LTS', 'vcpu' => 2, 'ram' => '4 GB', 'disk' => '50 GB', 'url' => url('/panel')],
            'app' => ['name' => 'Survival', 'address' => '203.0.113.10:25565', 'template' => 'Minecraft Paper', 'url' => url('/panel')],
            'ticket' => ['number' => '1042', 'subject' => 'Nie działa SSH', 'department' => 'Wsparcie techniczne', 'priority' => 'wysoki', 'url' => url('/panel'), 'admin_url' => url('/panel')],
            'customer' => ['email' => 'klient@example.com'], 'staff' => ['name' => 'Anna'],
            'invoice' => ['number' => 'FV/2026/0042', 'total' => '49,00 PLN', 'due_at' => now()->addDays(7)->format('d.m.Y'), 'url' => url('/panel')],
            'payment' => ['method' => 'Stripe'], 'wallet' => ['balance' => '120,50 PLN', 'url' => url('/panel')],
            'service' => ['name' => 'VPS S — vps1.example.com', 'url' => url('/panel')], 'billing' => ['url' => url('/panel')],
            'keepalive' => ['until' => now()->addDay()->format('d.m.Y H:i')],
        ];
    }
}
