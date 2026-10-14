<?php

/*
 * Klient API rozliczeniowego panelu VirtHub (/api/v1/billing) — wspólny dla
 * modułów WHMCS, Blesta i Paymenter. Bez zależności: cURL i JSON.
 * Ten plik jest kopiowany do każdego modułu (integrations/build.php) —
 * edytuj wersję w integrations/shared/.
 *
 * VirtHub billing API client shared by the WHMCS, Blesta and Paymenter modules.
 */

namespace VirtHub\Billing;

if (!class_exists(__NAMESPACE__ . '\\ClientException', false)) {
    class ClientException extends \RuntimeException
    {
        /** @var array<string, mixed> */
        public $response;

        public function __construct($message, $status = 0, array $response = [])
        {
            parent::__construct($message, (int) $status);
            $this->response = $response;
        }

        public function status()
        {
            return $this->getCode();
        }
    }
}

if (!class_exists(__NAMESPACE__ . '\\Client', false)) {
    class Client
    {
        const VERSION = '1.0.0';

        const POWER_ACTIONS = ['start', 'stop', 'reboot', 'force-off'];

        /** @var string */
        private $baseUrl;

        /** @var string */
        private $token;

        /** @var int */
        private $timeout;

        /** @var callable|null fn(string $method, string $url, array $headers, ?string $body): array{0:int,1:string} */
        private $transport;

        /** @var string|null język komunikatów panelu (Accept-Language), np. pl, en */
        private $locale;

        /**
         * @param string $panelUrl Adres panelu, np. https://panel.example.com (albo pełny …/api/v1)
         * @param string $token Token API z zakresem „billing”
         */
        public function __construct($panelUrl, $token, $timeout = 30, $transport = null, $locale = null)
        {
            $this->locale = $locale ? strtolower(substr((string) $locale, 0, 2)) : null;
            $url = rtrim(trim((string) $panelUrl), '/');
            if ($url !== '' && !preg_match('#^https?://#i', $url)) {
                $url = 'https://' . $url;
            }
            if (!preg_match('#/api/v1$#', $url)) {
                $url .= '/api/v1';
            }
            $this->baseUrl = $url;
            $this->token = trim((string) $token);
            $this->timeout = (int) $timeout;
            $this->transport = $transport;
        }

        public function baseUrl()
        {
            return $this->baseUrl;
        }

        /** Identyfikator usługi w panelu: przedrostek systemu + id usługi, np. whmcs-123. */
        public static function reference($system, $serviceId)
        {
            return strtolower($system) . '-' . $serviceId;
        }

        // --- konfiguracja --------------------------------------------------------

        /** Pakiety, systemy i lokalizacje (lista wyboru w konfiguracji produktu). */
        public function catalog()
        {
            return $this->request('GET', '/billing/catalog');
        }

        /** Sprawdzenie połączenia i uprawnień tokenu. */
        public function testConnection()
        {
            $this->catalog();

            return true;
        }

        // --- cykl życia usługi -----------------------------------------------------

        /**
         * @param array{billing_reference:string, customer_email:string, customer_name:string, customer_reference?:string,
         *              package:string, template:int, hostname:string, ssh_keys?:list<string>, location?:int} $data
         */
        public function provision(array $data)
        {
            return $this->request('POST', '/billing/provision', $data);
        }

        public function service($reference)
        {
            $response = $this->request('GET', '/billing/services/' . rawurlencode($reference));

            return isset($response['data']) ? $response['data'] : $response;
        }

        public function suspend($reference, $reason = null)
        {
            return $this->request('POST', $this->path($reference, 'suspend'), $reason ? ['reason' => $reason] : []);
        }

        public function unsuspend($reference)
        {
            return $this->request('POST', $this->path($reference, 'unsuspend'));
        }

        public function terminate($reference)
        {
            try {
                return $this->request('POST', $this->path($reference, 'terminate'), ['confirm' => true]);
            } catch (ClientException $e) {
                // Usługa już usunięta w panelu — dla billingu to sukces.
                if ($e->status() === 404) {
                    return ['message' => 'already terminated', 'idempotent' => true];
                }
                throw $e;
            }
        }

        public function changePackage($reference, $package)
        {
            return $this->request('POST', $this->path($reference, 'resize'), ['package' => $package]);
        }

        // --- sterowanie ------------------------------------------------------------

        public function power($reference, $action)
        {
            if (!in_array($action, self::POWER_ACTIONS, true)) {
                throw new ClientException('Unknown power action: ' . $action, 422);
            }

            return $this->request('POST', $this->path($reference, 'power'), ['action' => $action]);
        }

        public function reinstall($reference, $template, array $sshKeys = [])
        {
            return $this->request('POST', $this->path($reference, 'rebuild'), [
                'template' => (int) $template,
                'ssh_keys' => array_values(array_filter(array_map('trim', $sshKeys))),
                'confirm' => true,
            ]);
        }

        public function resetPassword($reference)
        {
            return $this->request('POST', $this->path($reference, 'password'));
        }

        /** @return array{username:string, password:?string, available:bool} */
        public function credentials($reference)
        {
            return $this->request('POST', $this->path($reference, 'credentials'));
        }

        /** @return string adres konsoli w przeglądarce (ważny 60 s) */
        public function consoleUrl($reference)
        {
            $response = $this->request('POST', $this->path($reference, 'console'));

            return (string) $response['url'];
        }

        /** @return string jednorazowy link logowania klienta na stronę maszyny w panelu */
        public function ssoUrl($reference)
        {
            $response = $this->request('POST', $this->path($reference, 'sso'));

            return (string) $response['url'];
        }

        public function metrics($reference, $range = 'day')
        {
            return $this->request('GET', $this->path($reference, 'metrics') . '?range=' . rawurlencode($range));
        }

        // --- pomocnicze dla modułów ------------------------------------------------

        /** Pierwszy adres IPv4 (albo pierwszy w ogóle) z odpowiedzi service(). */
        public static function primaryIp(array $service)
        {
            $ips = isset($service['ip_addresses']) ? (array) $service['ip_addresses'] : [];
            foreach ($ips as $ip) {
                if (!empty($ip['primary'])) {
                    return $ip['address'];
                }
            }

            return isset($ips[0]['address']) ? $ips[0]['address'] : null;
        }

        /** @return list<string> */
        public static function allIps(array $service)
        {
            return array_values(array_map(function ($ip) {
                return $ip['address'];
            }, isset($service['ip_addresses']) ? (array) $service['ip_addresses'] : []));
        }

        /** Klucze SSH z pola tekstowego (jeden na linię). */
        public static function parseSshKeys($text)
        {
            $keys = preg_split('/\r?\n/', (string) $text) ?: [];

            return array_values(array_filter(array_map('trim', $keys), function ($key) {
                return $key !== '' && preg_match('/^(ssh-(rsa|ed25519|dss)|ecdsa-sha2-\S+|sk-\S+)\s/', $key);
            }));
        }

        /** Poprawna nazwa hosta z domeny usługi albo zastępcza (np. vps123.example.com). */
        public static function hostname($domain, $fallbackPrefix, $fallbackDomain = 'vps.local')
        {
            $domain = strtolower(trim((string) $domain));
            if (preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]$/', $domain)) {
                return $domain;
            }

            return strtolower(preg_replace('/[^a-z0-9-]/i', '', $fallbackPrefix)) . '.' . ltrim($fallbackDomain ?: 'vps.local', '.');
        }

        /** Bajty → „12.3 GB” (1 GB = 1000³ B, jak w panelu). */
        public static function formatBytes($bytes)
        {
            $bytes = (float) $bytes;
            foreach (['B', 'KB', 'MB', 'GB', 'TB'] as $i => $unit) {
                if ($bytes < 1000 || $unit === 'TB') {
                    return ($i === 0 ? (int) $bytes : number_format($bytes, 1, '.', '')) . ' ' . $unit;
                }
                $bytes /= 1000;
            }

            return '';
        }

        // --- HTTP ------------------------------------------------------------------

        private function path($reference, $action)
        {
            return '/billing/services/' . rawurlencode($reference) . '/' . $action;
        }

        /** @return array<string, mixed> */
        public function request($method, $path, $body = null)
        {
            if ($this->token === '') {
                throw new ClientException('VirtHub: missing API token', 401);
            }
            $url = $this->baseUrl . $path;
            $headers = [
                'Authorization: Bearer ' . $this->token,
                'Accept: application/json',
                'User-Agent: VirtHub-Billing/' . self::VERSION,
            ];
            if ($this->locale) {
                $headers[] = 'Accept-Language: ' . $this->locale;
            }
            $payload = null;
            if ($body !== null && $method !== 'GET') {
                $payload = json_encode($body);
                $headers[] = 'Content-Type: application/json';
            }

            list($status, $raw) = $this->transport
                ? call_user_func($this->transport, $method, $url, $headers, $payload)
                : $this->curl($method, $url, $headers, $payload);

            $data = json_decode((string) $raw, true);
            if (!is_array($data)) {
                $data = [];
            }
            if ($status < 200 || $status >= 300) {
                throw new ClientException(self::errorMessage($status, $data, $raw), $status, $data);
            }

            return $data;
        }

        private static function errorMessage($status, array $data, $raw)
        {
            $message = isset($data['message']) ? (string) $data['message'] : '';
            if (!empty($data['errors']) && is_array($data['errors'])) {
                $details = [];
                foreach ($data['errors'] as $field => $errors) {
                    $details[] = $field . ': ' . implode(' ', (array) $errors);
                }
                $message = trim($message . ' (' . implode('; ', $details) . ')');
            }
            if ($message === '') {
                $message = $status === 0 ? 'Connection failed' : 'HTTP ' . $status . ' ' . substr(strip_tags((string) $raw), 0, 200);
            }

            return 'VirtHub: ' . $message;
        }

        private function curl($method, $url, array $headers, $payload)
        {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            if ($payload !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            }
            $raw = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($raw === false) {
                $error = curl_error($ch);
                curl_close($ch);
                throw new ClientException('VirtHub: ' . $error, 0);
            }
            curl_close($ch);

            return [$status, $raw];
        }
    }
}
