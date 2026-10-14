<?php

namespace Paymenter\Extensions\Servers\VirtHub;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Server;
use App\Helpers\ExtensionHelper;
use App\Models\Product;
use App\Models\Service;
use Exception;
use Illuminate\Support\Facades\View;
use VirtHub\Billing\Client;
use VirtHub\Billing\ClientException;
use VirtHub\Billing\Lang;

require_once __DIR__ . '/lib/VirtHubClient.php';
require_once __DIR__ . '/lib/Lang.php';

/**
 * VirtHub — rozszerzenie serwerowe Paymentera (Admin → Servers → VirtHub).
 *
 * Zakładanie, zawieszanie, odwieszanie, usuwanie i zmiana pakietu VPS-a, a na
 * stronie usługi klienta: zasilanie, konsola, logowanie do VirtHub, zakładka
 * „Sterowanie serwerem” ze stanem, zasobami, transferem, reinstalacją i resetem hasła.
 */
#[ExtensionMeta(
    name: 'VirtHub',
    description: 'VirtHub VPS provisioning and server control',
    version: '1.0.0',
    author: 'VirtHub',
    url: 'https://github.com/TeYroXOfficial/virthub',
)]
class VirtHub extends Server
{
    public function boot()
    {
        View::addNamespace('virthub', __DIR__ . '/resources/views');
        require __DIR__ . '/routes.php';
    }

    public static function t(string $key, array $vars = []): string
    {
        return Lang::get(__DIR__ . '/lang', app()->getLocale(), $key, $vars);
    }

    private function api(): Client
    {
        return new Client((string) $this->config('url'), (string) $this->config('token'), 30, null, app()->getLocale());
    }

    private static function reference(Service $service): string
    {
        return Client::reference('paymenter', $service->id);
    }

    // --- konfiguracja ------------------------------------------------------------------

    public function getConfig($values = []): array
    {
        return [
            ['name' => 'url', 'type' => 'text', 'label' => self::t('panel_url'), 'placeholder' => 'https://panel.example.com', 'required' => true, 'validation' => 'url'],
            ['name' => 'token', 'type' => 'text', 'label' => self::t('api_token'), 'description' => self::t('api_token_hint'), 'required' => true, 'encrypted' => true],
        ];
    }

    public function testConfig(): bool|string
    {
        try {
            $this->api()->testConnection();
        } catch (Exception $e) {
            return $e->getMessage();
        }

        return true;
    }

    public function getProductConfig($values = []): array
    {
        $catalog = $this->api()->catalog();
        $packages = [];
        foreach ($catalog['packages'] as $p) {
            $packages[$p['slug']] = sprintf('%s (%d vCPU, %d MB RAM, %d GB)', $p['name'], $p['vcpu'], $p['ram_mb'], $p['disk_gb']);
        }
        $templates = array_column($catalog['templates'], 'name', 'id');
        $locations = ['' => self::t('config_any_location')] + array_column($catalog['locations'], 'name', 'id');

        return [
            ['name' => 'package', 'type' => 'select', 'label' => self::t('config_package'), 'description' => self::t('config_package_hint'), 'required' => true, 'options' => $packages],
            ['name' => 'template', 'type' => 'select', 'label' => self::t('config_template'), 'description' => self::t('config_template_hint'), 'required' => true, 'options' => $templates],
            ['name' => 'location', 'type' => 'select', 'label' => self::t('config_location'), 'description' => self::t('config_location_hint'), 'required' => false, 'options' => $locations],
            ['name' => 'hostname_domain', 'type' => 'text', 'label' => self::t('config_domain'), 'description' => self::t('config_domain_hint'), 'default' => 'vps.example.com', 'required' => false],
        ];
    }

    public function getCheckoutConfig(Product $product, $values = [], $settings = []): array
    {
        $templates = [];
        try {
            $templates = array_column($this->api()->catalog()['templates'], 'name', 'id');
        } catch (Exception $e) {
            // Bez listy klient dostaje system domyślny z produktu.
        }
        $fields = [
            ['name' => 'hostname', 'type' => 'text', 'label' => self::t('checkout_hostname'), 'placeholder' => 'vps1.example.com', 'required' => false,
                'validation' => 'nullable|regex:/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]$/i'],
        ];
        if ($templates) {
            $fields[] = ['name' => 'os', 'type' => 'select', 'label' => self::t('checkout_os'), 'required' => true, 'options' => $templates, 'default' => $settings['template'] ?? null];
        }
        $fields[] = ['name' => 'ssh_key', 'type' => 'text', 'label' => self::t('checkout_ssh_key'), 'placeholder' => 'ssh-ed25519 AAAA…', 'required' => false];

        return $fields;
    }

    // --- cykl życia ---------------------------------------------------------------------

    public function createServer(Service $service, $settings, $properties)
    {
        $reference = self::reference($service);
        $user = $service->user;
        $name = trim((string) ($user->name ?? trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''))));
        $request = [
            'billing_reference' => $reference,
            'customer_email' => $user->email,
            'customer_name' => $name !== '' ? $name : $user->email,
            'customer_reference' => 'paymenter-user-' . $user->id,
            'package' => $settings['package'],
            'template' => (int) ($properties['os'] ?? $settings['template']),
            'hostname' => Client::hostname($properties['hostname'] ?? '', 'vps' . $service->id, $settings['hostname_domain'] ?? 'vps.local'),
            'ssh_keys' => Client::parseSshKeys($properties['ssh_key'] ?? ''),
        ];
        if (!empty($settings['location'])) {
            $request['location'] = (int) $settings['location'];
        }

        $api = $this->api();
        $response = $api->provision($request);
        $this->property($service, 'virthub_reference', 'VirtHub', $reference);
        $this->storePassword($service, $api);

        return $response;
    }

    public function suspendServer(Service $service, $settings, $properties)
    {
        $this->api()->suspend(self::reference($service));

        return true;
    }

    public function unsuspendServer(Service $service, $settings, $properties)
    {
        $this->api()->unsuspend(self::reference($service));

        return true;
    }

    public function terminateServer(Service $service, $settings, $properties)
    {
        $this->api()->terminate(self::reference($service));

        return true;
    }

    public function upgradeServer(Service $service, $settings, $properties)
    {
        try {
            $this->api()->changePackage(self::reference($service), $settings['package']);
        } catch (ClientException $e) {
            if ($e->status() === 409) {
                throw new Exception(self::t('resize_needs_stop') . ' (' . $e->getMessage() . ')');
            }
            throw $e;
        }

        return true;
    }

    // --- strona usługi klienta -------------------------------------------------------------

    public function getActions(Service $service, $settings = [], $properties = []): array
    {
        $actions = [
            ['type' => 'view', 'name' => 'virthub', 'label' => self::t('actions'), 'function' => 'controlView'],
            ['type' => 'button', 'label' => self::t('open_panel'), 'function' => 'ssoUrl'],
        ];
        try {
            $data = $this->api()->service(self::reference($service));
        } catch (Exception $e) {
            return $actions;
        }
        $actions[] = ['type' => 'text', 'label' => self::t('status'), 'text' => self::t('state.' . $data['state'])];
        $actions[] = ['type' => 'text', 'label' => self::t('hostname'), 'text' => $data['hostname']];
        $actions[] = ['type' => 'text', 'label' => self::t('ip_addresses'), 'text' => implode(', ', Client::allIps($data))];
        if ($data['state'] === 'running') {
            $actions[] = ['type' => 'button', 'label' => self::t('console'), 'function' => 'consoleUrl'];
            $actions[] = ['type' => 'button', 'label' => self::t('reboot'), 'function' => 'powerReboot'];
            $actions[] = ['type' => 'button', 'label' => self::t('stop'), 'function' => 'powerStop'];
            $actions[] = ['type' => 'button', 'label' => self::t('force_off'), 'function' => 'powerForceOff'];
        } else {
            $actions[] = ['type' => 'button', 'label' => self::t('start'), 'function' => 'powerStart'];
        }

        return $actions;
    }

    public function ssoUrl(Service $service, $settings = [], $properties = []): string
    {
        return $this->api()->ssoUrl(self::reference($service));
    }

    public function consoleUrl(Service $service, $settings = [], $properties = []): string
    {
        return $this->api()->consoleUrl(self::reference($service));
    }

    public function powerStart(Service $service, $settings = [], $properties = []): string
    {
        return $this->power($service, 'start');
    }

    public function powerStop(Service $service, $settings = [], $properties = []): string
    {
        return $this->power($service, 'stop');
    }

    public function powerReboot(Service $service, $settings = [], $properties = []): string
    {
        return $this->power($service, 'reboot');
    }

    public function powerForceOff(Service $service, $settings = [], $properties = []): string
    {
        return $this->power($service, 'force-off');
    }

    private function power(Service $service, string $action): string
    {
        $this->api()->power(self::reference($service), $action);
        session()->flash('virthub_message', self::t('action_sent'));

        return route('services.show', ['service' => $service, 'tab' => 'virthub']);
    }

    /** Zakładka „Sterowanie serwerem”. */
    public function controlView(Service $service, $settings = [], $properties = [], $view = null)
    {
        $api = $this->api();
        try {
            $data = $api->service(self::reference($service));
            $this->storePassword($service, $api);
            $templates = array_values(array_filter($api->catalog()['templates'], fn ($t) => $t['virtualization'] === $data['virtualization']));
        } catch (Exception $e) {
            return view('virthub::control', ['error' => self::t('error_unavailable', ['error' => $e->getMessage()]), 'service' => $service])->render();
        }
        $traffic = $data['traffic'] ?? [];

        return view('virthub::control', [
            'service' => $service,
            'server' => $data,
            'ips' => Client::allIps($data),
            'templates' => $templates,
            'traffic' => self::t('traffic_of', [
                'used' => Client::formatBytes($traffic['used'] ?? 0),
                'limit' => !empty($traffic['limit']) ? Client::formatBytes($traffic['limit']) : self::t('unlimited'),
            ]),
            'trafficPercent' => $traffic['percent'] ?? null,
            'password' => $service->properties()->where('key', 'virthub_password')->value('value'),
            'username' => $service->properties()->where('key', 'virthub_username')->value('value') ?: 'root',
        ])->render();
    }

    // --- akcje z formularzy (routes.php) ------------------------------------------------------

    public function reinstallFromRequest(Service $service, $settings, $properties, array $input): void
    {
        if (empty($input['confirm'])) {
            throw new Exception(self::t('reinstall_need_confirm'));
        }
        $this->api()->reinstall(self::reference($service), (int) ($input['template'] ?? 0), Client::parseSshKeys($input['ssh_keys'] ?? ''));
    }

    public function resetPasswordFromRequest(Service $service, $settings, $properties): void
    {
        $this->api()->resetPassword(self::reference($service));
    }

    public function fetchPasswordFromRequest(Service $service, $settings, $properties): bool
    {
        return $this->storePassword($service, $this->api());
    }

    // --- pomocnicze -----------------------------------------------------------------------------

    private function storePassword(Service $service, Client $api): bool
    {
        $credentials = $api->credentials(self::reference($service));
        if (empty($credentials['available'])) {
            return false;
        }
        $this->property($service, 'virthub_username', self::t('username'), $credentials['username']);
        $this->property($service, 'virthub_password', self::t('password'), $credentials['password']);

        return true;
    }

    private function property(Service $service, string $key, string $name, ?string $value): void
    {
        $service->properties()->updateOrCreate(['key' => $key], ['name' => $name, 'value' => (string) $value]);
    }

    /** Wywołanie metody rozszerzenia z ustawieniami serwera, do którego należy usługa. */
    public static function callForService(Service $service, string $method, array $args = [])
    {
        return ExtensionHelper::callService($service, $method, $args, mayFail: false);
    }
}
