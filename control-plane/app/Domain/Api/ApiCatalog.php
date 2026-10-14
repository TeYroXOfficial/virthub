<?php

namespace App\Domain\Api;

/**
 * Opis publicznego API panelu — jedno źródło dla strony dokumentacji, pliku
 * OpenAPI 3 i kolekcji Postman. Teksty przechodzą przez __(), więc
 * dokumentacja jest w języku panelu (także w językach dodanych w
 * Administracji → Języki).
 *
 * Endpoint: [id, grupa, metoda, ścieżka, tytuł, opis, zakres tokenu,
 *            parametry ścieżki, zapytania i treści, przykładowa odpowiedź].
 */
class ApiCatalog
{
    /** @return array<string, array{name:string, description:string, auth:string}> */
    public static function groups(): array
    {
        return [
            'account' => ['name' => __('Konto'), 'description' => __('Dane właściciela tokenu.'), 'auth' => 'token'],
            'catalog' => ['name' => __('Oferta'), 'description' => __('Pakiety i systemy dostępne do zamówienia.'), 'auth' => 'token'],
            'servers' => ['name' => __('Maszyny'), 'description' => __('Tworzenie, odczyt i usuwanie maszyn wirtualnych i kontenerów.'), 'auth' => 'token'],
            'power' => ['name' => __('Zasilanie i system'), 'description' => __('Uruchamianie, zatrzymanie, reinstalacja, zmiana pakietu, dostęp.'), 'auth' => 'token'],
            'metrics' => ['name' => __('Metryki'), 'description' => __('Zużycie CPU, RAM, dysku i sieci.'), 'auth' => 'token'],
            'firewall' => ['name' => __('Zapora'), 'description' => __('Reguły i polityka zapory maszyny.'), 'auth' => 'token'],
            'snapshots' => ['name' => __('Migawki i kopie'), 'description' => __('Migawki dysku i kopie zapasowe maszyny.'), 'auth' => 'token'],
            'admin' => ['name' => __('Administracja'), 'description' => __('Hypervisory, pule IP, pakiety, szablony i maszyny wszystkich klientów. Token personelu z zakresem admin.'), 'auth' => 'admin'],
            'billing' => ['name' => __('Integracja rozliczeniowa'), 'description' => __('Dla systemów WHMCS, Blesta i własnych — token z zakresem billing.'), 'auth' => 'billing'],
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function endpoints(): array
    {
        $server = self::path('server', __('Identyfikator maszyny'), 42);
        $serverExample = self::serverExample();
        $job = ['message' => __('Polecenie zostało przekazane do hypervisora.'), 'job_id' => 1234];
        $ref = self::path('reference', 'billing_reference', 'whmcs-service-1001', 'string');

        return [
            // --- konto --------------------------------------------------------
            self::e('me', 'account', 'GET', '/me', __('Bieżący użytkownik'), __('Zwraca konto, do którego należy token. Dobre do sprawdzenia, czy token działa.'), null,
                response: ['id' => 7, 'name' => 'Jan Kowalski', 'email' => 'jan@example.com', 'role' => 'customer']),

            // --- oferta -------------------------------------------------------
            self::e('packages', 'catalog', 'GET', '/packages', __('Lista pakietów'), __('Aktywne pakiety VPS z zasobami. Pole slug podajesz przy zamawianiu.'), 'servers:read',
                response: ['data' => [['slug' => 'vps-s', 'name' => 'VPS S', 'description' => null, 'vcpu' => 2, 'ram_mb' => 4096, 'ram_gb' => 4, 'disk_gb' => 50, 'bandwidth_gb' => 2000, 'cpu_limit_percent' => null, 'ip_count' => 1, 'ipv6_count' => 1, 'network_type' => 'public', 'price_hint' => 49.0, 'currency' => 'PLN']]]),
            self::e('templates', 'catalog', 'GET', '/os-templates', __('Lista systemów'), __('Systemy operacyjne do instalacji. Pole id podajesz jako template.'), 'servers:read',
                response: ['data' => [['id' => 3, 'name' => 'Ubuntu 24.04 LTS', 'family' => 'ubuntu', 'group' => ['id' => 1, 'name' => 'Ubuntu'], 'virtualization' => 'kvm', 'version' => '24.04', 'min_disk_gb' => 10, 'self_service' => true]]]),

            // --- maszyny ------------------------------------------------------
            self::e('servers.index', 'servers', 'GET', '/servers', __('Lista maszyn'), __('Maszyny właściciela tokenu, po 25 na stronę (parametr page).'), 'servers:read',
                query: [self::param('page', 'integer', false, __('Numer strony'), 1)],
                response: ['data' => [$serverExample], 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 1]]),
            self::e('servers.store', 'servers', 'POST', '/servers', __('Zamówienie maszyny'), __('Tworzy maszynę z pakietu i systemu. Budowa trwa zwykle około minuty — stan sprawdzisz w GET /servers/{server} (state: building → running). Hasło roota odbierzesz raz przez /credentials.'), 'servers:write',
                body: [
                    self::param('package', 'string', true, __('Slug pakietu z GET /packages'), 'vps-s'),
                    self::param('template', 'integer', true, __('Id systemu z GET /os-templates'), 3),
                    self::param('hostname', 'string', true, __('Nazwa hosta (FQDN)'), 'vps1.example.com'),
                    self::param('label', 'string', false, __('Własna etykieta w panelu'), 'web-01'),
                    self::param('location', 'integer', false, __('Id lokalizacji (grupy hypervisorów)'), null),
                    self::param('ssh_keys', 'array', false, __('Publiczne klucze SSH (do 10)'), ['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAI… user@laptop']),
                ],
                status: 201, response: ['data' => array_merge($serverExample, ['state' => 'building', 'build_progress' => 0])]),
            self::e('servers.show', 'servers', 'GET', '/servers/{server}', __('Szczegóły maszyny'), __('Stan, zasoby, adresy IP, system i transfer w bieżącym miesiącu.'), 'servers:read',
                path: [$server], response: ['data' => $serverExample]),
            self::e('servers.destroy', 'servers', 'DELETE', '/servers/{server}', __('Usunięcie maszyny'), __('Trwale usuwa maszynę razem z dyskiem i zwalnia adresy IP.'), 'servers:write',
                path: [$server], status: 202, response: ['message' => __('Maszyna została zgłoszona do usunięcia.'), 'job_id' => 1234]),

            // --- zasilanie i system -------------------------------------------
            self::e('power', 'power', 'POST', '/servers/{server}/power', __('Zasilanie'), __('start, stop (łagodne wyłączenie), reboot albo force-off (odcięcie zasilania).'), 'servers:write',
                path: [$server], body: [self::param('action', 'string', true, __('Operacja'), 'reboot', ['start', 'stop', 'reboot', 'force-off'])], status: 202, response: $job),
            self::e('rebuild', 'power', 'POST', '/servers/{server}/rebuild', __('Reinstalacja systemu'), __('Instaluje system od nowa — kasuje dane na dysku. Nowe hasło roota przez /credentials.'), 'servers:write',
                path: [$server], body: [
                    self::param('template', 'integer', true, __('Id systemu'), 3),
                    self::param('ssh_keys', 'array', false, __('Publiczne klucze SSH'), []),
                    self::param('confirm', 'boolean', true, __('Musi być true'), true),
                ], status: 202, response: $job),
            self::e('resize', 'power', 'POST', '/servers/{server}/resize', __('Zmiana pakietu'), __('Przenosi maszynę na inny pakiet. Maszyna musi być zatrzymana; dysku nie da się zmniejszyć.'), 'servers:write',
                path: [$server], body: [self::param('package', 'string', true, __('Slug nowego pakietu'), 'vps-m')], status: 202, response: $job),
            self::e('credentials', 'power', 'GET', '/servers/{server}/credentials', __('Hasło roota'), __('Hasło początkowe — zwracane tylko raz. Kolejne wywołanie zwraca available: false.'), 'servers:write',
                path: [$server], response: ['username' => 'root', 'password' => 'Xk9…', 'available' => true, 'note' => __('Zapisz je teraz — nie zobaczysz go ponownie.')]),
            self::e('console', 'power', 'POST', '/servers/{server}/console-token', __('Bilet konsoli'), __('Jednorazowy adres konsoli w przeglądarce (ważny 60 s). Maszyna musi działać.'), 'servers:write',
                path: [$server], response: ['token' => 'q8f…', 'expires_in' => 60, 'url' => url('/console/q8f…')]),

            // --- metryki ------------------------------------------------------
            self::e('metrics', 'metrics', 'GET', '/servers/{server}/metrics', __('Historia zużycia'), __('Uśrednione próbki CPU, RAM, dysku i sieci za godzinę, dobę albo tydzień.'), 'servers:read',
                path: [$server], query: [self::param('range', 'string', false, __('Zakres'), 'day', ['hour', 'day', 'week'])],
                response: ['range' => 'day', 'points' => [['t' => '2026-10-14T10:00:00Z', 'cpu' => 12.5, 'ram_mb' => 1530, 'disk_read_bps' => 0, 'disk_write_bps' => 20480, 'net_rx_bps' => 1200, 'net_tx_bps' => 800]]]),
            self::e('metrics.live', 'metrics', 'GET', '/servers/{server}/metrics/live', __('Zużycie na żywo'), __('Bieżący odczyt prosto z hypervisora (limit 90 zapytań na minutę). Tylko działająca maszyna.'), 'servers:read',
                path: [$server], response: ['cpu' => 7.1, 'ram_used_mb' => 1512, 'ram_total_mb' => 4096, 'disk_read_bps' => 0, 'disk_write_bps' => 4096, 'net_rx_bps' => 512, 'net_tx_bps' => 256]),
            self::e('stats', 'metrics', 'GET', '/servers/{server}/stats', __('Surowe próbki'), __('Próbki z ostatnich N godzin (1–720).'), 'servers:read',
                path: [$server], query: [self::param('hours', 'integer', false, __('Okno w godzinach'), 24)],
                response: ['server_id' => 42, 'window_hours' => 24, 'samples' => [['sampled_at' => '2026-10-14T10:00:00Z', 'cpu_percent' => 12.5, 'ram_used_mb' => 1530, 'net_rx_bytes' => 10240, 'net_tx_bytes' => 4096]]]),

            // --- zapora -------------------------------------------------------
            self::e('firewall.index', 'firewall', 'GET', '/servers/{server}/firewall', __('Reguły zapory'), __('Polityka (domyślna akcja dla ruchu przychodzącego i wychodzącego) i lista reguł.'), 'servers:read',
                path: [$server], response: ['policy' => ['enabled' => true, 'inbound' => 'drop', 'outbound' => 'accept', 'locked' => false, 'editable' => true],
                    'data' => [['id' => 9, 'managed_by' => 'customer', 'enabled' => true, 'action' => 'accept', 'direction' => 'in', 'protocol' => 'tcp', 'port_from' => 22, 'port_to' => null, 'source' => null, 'comment' => 'SSH']]]),
            self::e('firewall.store', 'firewall', 'POST', '/servers/{server}/firewall', __('Nowa reguła'), __('Dodaje regułę i wdraża ją na hypervisorze w ciągu kilku sekund.'), 'servers:write',
                path: [$server], body: [
                    self::param('action', 'string', true, __('Akcja'), 'accept', ['accept', 'drop']),
                    self::param('direction', 'string', true, __('Kierunek'), 'in', ['in', 'out']),
                    self::param('protocol', 'string', true, __('Protokół'), 'tcp', ['tcp', 'udp', 'icmp', 'any']),
                    self::param('port_from', 'integer', false, __('Port (albo początek zakresu)'), 443),
                    self::param('port_to', 'integer', false, __('Koniec zakresu portów'), null),
                    self::param('source', 'string', false, __('Adres albo sieć drugiej strony (CIDR)'), '0.0.0.0/0'),
                    self::param('comment', 'string', false, __('Opis'), 'HTTPS'),
                ], status: 201, response: ['id' => 10, 'managed_by' => 'customer', 'description' => 'TCP 443', 'message' => __('Reguła została dodana i jest wdrażana na hypervisorze.')]),
            self::e('firewall.policy', 'firewall', 'PUT', '/servers/{server}/firewall/policy', __('Polityka zapory'), __('Włącza zaporę i ustawia domyślną akcję dla ruchu, którego nie dotyczy żadna reguła.'), 'servers:write',
                path: [$server], body: [
                    self::param('enabled', 'boolean', true, __('Zapora włączona'), true),
                    self::param('inbound', 'string', true, __('Ruch przychodzący'), 'drop', ['accept', 'drop']),
                    self::param('outbound', 'string', true, __('Ruch wychodzący'), 'accept', ['accept', 'drop']),
                ], response: ['policy' => ['enabled' => true, 'inbound' => 'drop', 'outbound' => 'accept'], 'message' => __('Ustawienia zapory są wdrażane na hypervisorze.')]),
            self::e('firewall.destroy', 'firewall', 'DELETE', '/servers/{server}/firewall/{rule}', __('Usunięcie reguły'), __('Usuwa regułę zapory.'), 'servers:write',
                path: [$server, self::path('rule', __('Identyfikator reguły'), 10)], response: ['message' => __('Reguła usunięta.')]),

            // --- migawki ------------------------------------------------------
            self::e('snapshots.index', 'snapshots', 'GET', '/servers/{server}/snapshots', __('Lista migawek'), __('Migawki dysku maszyny z limitem na maszynę.'), 'servers:read',
                path: [$server], response: ['data' => [['id' => 5, 'name' => 'przed-aktualizacja', 'status' => 'ready', 'created_at' => '2026-10-14T09:00:00Z']], 'used' => 1, 'limit' => 3]),
            self::e('snapshots.store', 'snapshots', 'POST', '/servers/{server}/snapshots', __('Nowa migawka'), __('Migawka spójna dyskowo — maszyna musi być zatrzymana.'), 'servers:write',
                path: [$server], body: [self::param('name', 'string', true, __('Nazwa (litery, cyfry, . _ -)'), 'przed-aktualizacja')], status: 202, response: $job),
            self::e('snapshots.restore', 'snapshots', 'POST', '/servers/{server}/snapshots/{backup}/restore', __('Przywrócenie'), __('Nadpisuje dysk zawartością migawki albo kopii zapasowej i uruchamia maszynę ponownie.'), 'servers:write',
                path: [$server, self::path('backup', __('Identyfikator migawki'), 5)], body: [self::param('confirm', 'boolean', true, __('Musi być true'), true)], status: 202, response: $job),
            self::e('snapshots.destroy', 'snapshots', 'DELETE', '/servers/{server}/snapshots/{backup}', __('Usunięcie migawki'), __('Usuwa migawkę (kopię zapasową także z celu kopii).'), 'servers:write',
                path: [$server, self::path('backup', __('Identyfikator migawki'), 5)], response: ['message' => __('Kopia została usunięta z listy.')]),

            // --- administracja ------------------------------------------------
            self::e('admin.hypervisors', 'admin', 'GET', '/admin/hypervisors', __('Hypervisory'), __('Węzły z pojemnością, stanem i liczbą maszyn.'), 'admin',
                response: ['data' => [['id' => 1, 'name' => 'node-1', 'status' => 'online', 'virtualization' => 'kvm', 'cpu_cores_total' => 32, 'ram_mb_total' => 131072, 'disk_gb_total' => 2000, 'servers_count' => 14]]]),
            self::e('admin.hypervisors.show', 'admin', 'GET', '/admin/hypervisors/{hypervisor}', __('Szczegóły hypervisora'), __('Konfiguracja i ostatni raport węzła.'), 'admin',
                path: [self::path('hypervisor', __('Identyfikator hypervisora'), 1)], response: ['data' => ['id' => 1, 'name' => 'node-1', 'status' => 'online']]),
            self::e('admin.hypervisors.update', 'admin', 'PUT', '/admin/hypervisors/{hypervisor}', __('Zmiana hypervisora'), __('Nazwa, stan (np. maintenance), przyjmowanie nowych maszyn, pojemność, grupa.'), 'admin',
                path: [self::path('hypervisor', __('Identyfikator hypervisora'), 1)], body: [
                    self::param('status', 'string', false, __('Stan'), 'maintenance', ['online', 'offline', 'maintenance']),
                    self::param('accepts_new_servers', 'boolean', false, __('Przyjmuje nowe maszyny'), false),
                    self::param('hypervisor_group_id', 'integer', false, __('Grupa (lokalizacja)'), 2),
                ], response: ['data' => ['id' => 1, 'status' => 'maintenance']]),
            self::e('admin.hypervisors.health', 'admin', 'GET', '/admin/hypervisors/{hypervisor}/health', __('Stan zdrowia węzła'), __('Odpytuje agenta na żywo: zasoby, maszyny, wersja.'), 'admin',
                path: [self::path('hypervisor', __('Identyfikator hypervisora'), 1)], response: ['status' => 'ok', 'running_vms' => 14, 'ram_mb_free' => 40960, 'disk_gb_free' => 900]),
            self::e('admin.ip-pools', 'admin', 'GET', '/admin/ip-pools', __('Pule adresów IP'), __('Pule z wykorzystaniem.'), 'admin',
                response: ['data' => [['id' => 3, 'name' => 'Publiczne', 'cidr' => '203.0.113.0/24', 'version' => 4, 'type' => 'public', 'addresses' => 254, 'assigned' => 40]]]),
            self::e('admin.ip-addresses', 'admin', 'GET', '/admin/ip-pools/{pool}/addresses', __('Adresy puli'), __('Adresy z rDNS, MAC i maszyną, po 100 na stronę.'), 'admin',
                path: [self::path('pool', __('Identyfikator puli'), 3)], response: ['data' => [['id' => 51, 'address' => '203.0.113.10', 'reserved' => false, 'rdns' => 'vps1.example.com', 'mac_address' => null, 'server' => ['id' => 42, 'hostname' => 'vps1.example.com']]]]),
            self::e('admin.rdns', 'admin', 'PUT', '/admin/ip-addresses/{address}/rdns', __('rDNS adresu'), __('Ustawia albo usuwa (null) rekord PTR.'), 'admin',
                path: [self::path('address', __('Identyfikator adresu'), 51)], body: [self::param('rdns', 'string', false, __('Nazwa hosta albo null'), 'vps1.example.com')], response: ['message' => __('Zapisano rDNS.')]),
            self::e('admin.servers', 'admin', 'GET', '/admin/servers', __('Maszyny wszystkich klientów'), __('Filtr po stanie, węźle i tekście (nazwa hosta, e-mail).'), 'admin',
                query: [self::param('state', 'string', false, __('Stan'), 'running'), self::param('hypervisor_id', 'integer', false, __('Węzeł'), null), self::param('q', 'string', false, __('Szukaj'), 'example.com')],
                response: ['data' => [$serverExample]]),
            self::e('admin.servers.suspend', 'admin', 'POST', '/admin/servers/{server}/suspend', __('Zawieszenie maszyny'), __('Zatrzymuje maszynę i blokuje operacje klienta.'), 'admin',
                path: [$server], body: [self::param('reason', 'string', true, __('Powód widoczny dla klienta'), __('Nadużycie'))], status: 202, response: $job),
            self::e('admin.servers.unsuspend', 'admin', 'POST', '/admin/servers/{server}/unsuspend', __('Odwieszenie maszyny'), __('Zdejmuje blokadę i uruchamia maszynę.'), 'admin',
                path: [$server], status: 202, response: $job),
            self::e('admin.packages.store', 'admin', 'POST', '/admin/packages', __('Nowy pakiet'), __('Dodaje pakiet VPS do oferty.'), 'admin',
                body: [
                    self::param('name', 'string', true, __('Nazwa'), 'VPS M'),
                    self::param('vcpu', 'integer', true, 'vCPU', 4),
                    self::param('ram_mb', 'integer', true, 'RAM (MB)', 8192),
                    self::param('disk_gb', 'integer', true, __('Dysk (GB)'), 100),
                    self::param('bandwidth_gb', 'integer', true, __('Transfer (GB/mies.)'), 4000),
                    self::param('ip_count', 'integer', true, __('Adresy IPv4'), 1),
                    self::param('ipv6_count', 'integer', false, __('Adresy IPv6'), 1),
                    self::param('network_type', 'string', false, __('Sieć'), 'public', ['public', 'nat']),
                ], status: 201, response: ['data' => ['id' => 4, 'slug' => 'vps-m']]),
            self::e('admin.audit', 'admin', 'GET', '/admin/audit-logs', __('Dziennik zdarzeń'), __('Kto, co i kiedy — po 100 na stronę.'), 'admin',
                query: [self::param('action', 'string', false, __('Rodzaj zdarzenia'), 'server.rebuild'), self::param('actor_id', 'integer', false, __('Użytkownik'), null)],
                response: ['data' => [['id' => 900, 'action' => 'server.rebuild', 'actor' => 'jan@example.com', 'subject' => 'Server#42', 'meta' => ['template' => 'Ubuntu 24.04 LTS'], 'ip_address' => '198.51.100.7', 'created_at' => '2026-10-14T10:00:00Z']]]),

            // --- billing ------------------------------------------------------
            self::e('billing.provision', 'billing', 'POST', '/billing/provision', __('Założenie usługi'), __('Tworzy konto klienta (jeśli nie istnieje) i maszynę. billing_reference to Twój identyfikator usługi — używasz go potem do zawieszania i usuwania. Wywołanie z tym samym billing_reference nie tworzy drugiej maszyny.'), 'billing',
                body: [
                    self::param('billing_reference', 'string', true, __('Id usługi w systemie rozliczeniowym'), 'whmcs-service-1001'),
                    self::param('customer_email', 'string', true, __('E-mail klienta'), 'klient@example.com'),
                    self::param('customer_name', 'string', true, __('Imię i nazwisko klienta'), 'Anna Nowak'),
                    self::param('customer_reference', 'string', false, __('Id klienta w systemie rozliczeniowym'), 'whmcs-client-77'),
                    self::param('package', 'string', true, __('Slug pakietu'), 'vps-s'),
                    self::param('template', 'integer', true, __('Id systemu'), 3),
                    self::param('hostname', 'string', true, __('Nazwa hosta'), 'vps1.example.com'),
                    self::param('ssh_keys', 'array', false, __('Publiczne klucze SSH'), []),
                ], status: 201, response: ['message' => __('Maszyna jest tworzona.'), 'server_id' => 42, 'customer_id' => 7, 'state' => 'building']),
            self::e('billing.suspend', 'billing', 'POST', '/billing/services/{reference}/suspend', __('Zawieszenie usługi'), __('Np. za brak płatności. Maszyna zostaje zatrzymana.'), 'billing',
                path: [self::path('reference', 'billing_reference', 'whmcs-service-1001', 'string')], body: [self::param('reason', 'string', false, __('Powód'), __('Brak płatności'))], status: 202, response: $job),
            self::e('billing.unsuspend', 'billing', 'POST', '/billing/services/{reference}/unsuspend', __('Odwieszenie usługi'), __('Po opłaceniu — maszyna zostaje uruchomiona.'), 'billing',
                path: [self::path('reference', 'billing_reference', 'whmcs-service-1001', 'string')], status: 202, response: $job),
            self::e('billing.terminate', 'billing', 'POST', '/billing/services/{reference}/terminate', __('Usunięcie usługi'), __('Trwale usuwa maszynę i dysk.'), 'billing',
                path: [self::path('reference', 'billing_reference', 'whmcs-service-1001', 'string')], body: [self::param('confirm', 'boolean', true, __('Musi być true'), true)], status: 202, response: $job),
            self::e('billing.sso', 'billing', 'POST', '/billing/sso-token', __('Logowanie jednym kliknięciem'), __('Jednorazowy link logowania klienta do panelu (np. przycisk „Zarządzaj serwerem” w WHMCS). Opcjonalnie od razu na stronę maszyny.'), 'billing',
                body: [self::param('customer_email', 'string', true, __('E-mail klienta'), 'klient@example.com'), self::param('server_id', 'integer', false, __('Id maszyny'), 42)],
                response: ['url' => url('/sso/abc…'), 'expires_in' => 30]),
            self::e('billing.catalog', 'billing', 'GET', '/billing/catalog', __('Katalog dla modułu'), __('Pakiety, systemy i lokalizacje — do list wyboru w konfiguracji produktu (WHMCS, Blesta, Paymenter).'), 'billing',
                response: ['packages' => [['slug' => 'vps-s', 'name' => 'VPS S', 'vcpu' => 1, 'ram_mb' => 1024, 'disk_gb' => 20, 'bandwidth_gb' => 2000, 'ip_count' => 1, 'ipv6_count' => 0]],
                    'templates' => [['id' => 3, 'name' => 'Debian 13', 'family' => 'debian', 'virtualization' => 'kvm', 'min_disk_gb' => 10]], 'locations' => [['id' => 1, 'name' => 'Warszawa']]]),
            self::e('billing.show', 'billing', 'GET', '/billing/services/{reference}', __('Stan usługi'), __('Stan, zasoby, adresy IP, system i transfer maszyny — do panelu klienta w systemie rozliczeniowym. busy = trwa operacja.'), 'billing',
                path: [$ref], response: ['data' => self::serverExample() + ['billing_reference' => 'whmcs-service-1001', 'busy' => false]]),
            self::e('billing.power', 'billing', 'POST', '/billing/services/{reference}/power', __('Zasilanie usługi'), __('start, stop, reboot albo force-off — przyciski w panelu klienta systemu rozliczeniowego.'), 'billing',
                path: [$ref], body: [self::param('action', 'string', true, __('Akcja'), 'reboot', ['start', 'stop', 'reboot', 'force-off'])], status: 202, response: $job),
            self::e('billing.rebuild', 'billing', 'POST', '/billing/services/{reference}/rebuild', __('Reinstalacja usługi'), __('Instaluje system od nowa — kasuje dane na dysku. Nowe hasło roota przez /credentials.'), 'billing',
                path: [$ref], body: [self::param('template', 'integer', true, __('Id systemu'), 3), self::param('ssh_keys', 'array', false, __('Publiczne klucze SSH'), []), self::param('confirm', 'boolean', true, __('Musi być true'), true)], status: 202, response: $job),
            self::e('billing.resize', 'billing', 'POST', '/billing/services/{reference}/resize', __('Zmiana pakietu usługi'), __('Upgrade albo downgrade z systemu rozliczeniowego. Maszyna musi być zatrzymana (inaczej 409); dysku nie da się zmniejszyć.'), 'billing',
                path: [$ref], body: [self::param('package', 'string', true, __('Slug nowego pakietu'), 'vps-m')], status: 202, response: $job),
            self::e('billing.password', 'billing', 'POST', '/billing/services/{reference}/password', __('Reset hasła usługi'), __('Generuje nowe hasło roota w działającym systemie; odbierasz je przez /credentials.'), 'billing',
                path: [$ref], status: 202, response: $job),
            self::e('billing.credentials', 'billing', 'POST', '/billing/services/{reference}/credentials', __('Hasło usługi'), __('Hasło początkowe, po reinstalacji albo resecie — zwracane jeden raz (moduł zapisuje je w danych usługi).'), 'billing',
                path: [$ref], response: ['username' => 'root', 'password' => 'Xy7…', 'available' => true]),
            self::e('billing.console', 'billing', 'POST', '/billing/services/{reference}/console', __('Konsola usługi'), __('Jednorazowy adres konsoli w przeglądarce (60 s). Maszyna musi działać.'), 'billing',
                path: [$ref], response: ['url' => url('/console/abc…'), 'expires_in' => 60]),
            self::e('billing.service_sso', 'billing', 'POST', '/billing/services/{reference}/sso', __('Logowanie na stronę usługi'), __('Jednorazowy link logowania właściciela prosto na stronę maszyny w panelu.'), 'billing',
                path: [$ref], response: ['url' => url('/sso/abc…'), 'expires_in' => 30]),
            self::e('billing.metrics', 'billing', 'GET', '/billing/services/{reference}/metrics', __('Zużycie usługi'), __('Uśrednione próbki CPU, RAM, dysku i sieci za godzinę, dobę albo tydzień.'), 'billing',
                path: [$ref], query: [self::param('range', 'string', false, __('Zakres'), 'day', ['hour', 'day', 'week'])], response: ['range' => 'day', 'points' => []]),
        ];
    }

    /** @return array<string, mixed> */
    public static function find(string $id): ?array
    {
        return collect(self::endpoints())->firstWhere('id', $id);
    }

    /** Wspólne odpowiedzi błędów (opis w każdym języku panelu). */
    public static function errors(): array
    {
        return [
            401 => __('Brak albo nieprawidłowy token (nagłówek Authorization: Bearer <token>).'),
            403 => __('Token nie ma wymaganego zakresu albo konto nie ma uprawnienia do tej operacji (także: adres IP spoza listy dozwolonych).'),
            404 => __('Nie ma takiego obiektu albo nie należy do Ciebie.'),
            409 => __('Operacja niemożliwa w obecnym stanie (np. trwa inna operacja, maszyna zatrzymana).'),
            422 => __('Błędne dane — szczegóły w polu errors.'),
            429 => __('Za dużo zapytań — odczekaj chwilę (nagłówek Retry-After).'),
        ];
    }

    // --- pomocnicze ------------------------------------------------------------

    private static function e(string $id, string $group, string $method, string $uri, string $summary, string $description, ?string $scope,
        array $path = [], array $query = [], array $body = [], int $status = 200, array $response = []): array
    {
        return [
            'id' => $id, 'group' => $group, 'method' => $method, 'path' => $uri, 'summary' => $summary, 'description' => $description,
            'scope' => $scope, 'path_params' => $path, 'query' => $query, 'body' => $body, 'status' => $status, 'response' => $response,
        ];
    }

    private static function param(string $name, string $type, bool $required, string $description, mixed $example, ?array $enum = null): array
    {
        return array_filter(['name' => $name, 'type' => $type, 'required' => $required, 'description' => $description, 'example' => $example, 'enum' => $enum],
            fn ($v, $k) => $k !== 'enum' || $v !== null, ARRAY_FILTER_USE_BOTH);
    }

    private static function path(string $name, string $description, mixed $example, string $type = 'integer'): array
    {
        return ['name' => $name, 'type' => $type, 'required' => true, 'description' => $description, 'example' => $example];
    }

    private static function serverExample(): array
    {
        return [
            'id' => 42, 'hostname' => 'vps1.example.com', 'label' => 'web-01', 'virtualization' => 'kvm',
            'state' => 'running', 'state_label' => __('Działa'), 'build_progress' => 100, 'suspended' => false,
            'resources' => ['vcpu' => 2, 'cpu_limit_percent' => null, 'ram_mb' => 4096, 'disk_gb' => 50, 'bandwidth_gb' => 2000],
            'package' => ['slug' => 'vps-s', 'name' => 'VPS S'],
            'os' => ['id' => 3, 'name' => 'Ubuntu 24.04 LTS', 'family' => 'ubuntu'],
            'ip_addresses' => [['address' => '203.0.113.10', 'version' => 4, 'primary' => true]],
            'traffic' => ['used' => 1073741824, 'limit' => 2000000000000, 'percent' => 0.1],
        ];
    }
}
