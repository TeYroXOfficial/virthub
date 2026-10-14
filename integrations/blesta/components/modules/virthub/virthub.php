<?php

/**
 * VirtHub — moduł Blesty (Settings → Company → Modules → VirtHub).
 *
 * Zakładanie, zawieszanie, odwieszanie, usuwanie i zmiana pakietu VPS-a,
 * a w zakładce „Sterowanie serwerem” (klient i administrator): zasilanie,
 * konsola, reinstalacja, reset hasła, logowanie do VirtHub.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'VirtHubClient.php';

use VirtHub\Billing\Client;
use VirtHub\Billing\ClientException;

class Virthub extends Module
{
    public function __construct()
    {
        $this->loadConfig(dirname(__FILE__) . DS . 'config.json');
        Loader::loadComponents($this, ['Input']);
        Language::loadLang('virthub', null, dirname(__FILE__) . DS . 'language' . DS);
    }

    // --- serwery (module rows) ---------------------------------------------------

    public function manageModule($module, array &$vars)
    {
        $this->view = $this->makeView('manage');
        $this->view->set('module', $module);

        return $this->view->fetch();
    }

    public function manageAddRow(array &$vars)
    {
        $this->view = $this->makeView('add_row');
        $this->view->set('vars', (object) $vars);

        return $this->view->fetch();
    }

    public function manageEditRow($module_row, array &$vars)
    {
        $this->view = $this->makeView('edit_row');
        if (empty($vars)) {
            $vars = (array) $module_row->meta;
            $vars['api_token'] = '';
        }
        $this->view->set('vars', (object) $vars);

        return $this->view->fetch();
    }

    public function addModuleRow(array &$vars)
    {
        $this->Input->setRules([
            'server_name' => ['empty' => ['rule' => 'isEmpty', 'negate' => true, 'message' => Language::_('Virthub.!error.server_name', true)]],
            'panel_url' => ['valid' => ['rule' => [[$this, 'validateUrl']], 'message' => Language::_('Virthub.!error.panel_url', true)]],
            'api_token' => [
                'empty' => ['rule' => 'isEmpty', 'negate' => true, 'message' => Language::_('Virthub.!error.api_token', true)],
                'connect' => ['rule' => [[$this, 'validateConnection'], isset($vars['panel_url']) ? $vars['panel_url'] : ''], 'message' => Language::_('Virthub.!error.connection', true)],
            ],
        ]);
        if (!$this->Input->validates($vars)) {
            return;
        }

        return [
            ['key' => 'server_name', 'value' => $vars['server_name'], 'encrypted' => 0],
            ['key' => 'panel_url', 'value' => rtrim($vars['panel_url'], '/'), 'encrypted' => 0],
            ['key' => 'api_token', 'value' => $vars['api_token'], 'encrypted' => 1],
        ];
    }

    public function editModuleRow($module_row, array &$vars)
    {
        if (empty($vars['api_token'])) {
            $vars['api_token'] = $module_row->meta->api_token;
        }

        return $this->addModuleRow($vars);
    }

    public function deleteModuleRow($module_row)
    {
        return null;
    }

    public function validateUrl($url)
    {
        return (bool) preg_match('#^https?://[^\s/]+#i', (string) $url);
    }

    public function validateConnection($token, $url)
    {
        try {
            return (new Client($url, $token))->testConnection();
        } catch (\Exception $e) {
            return false;
        }
    }

    // --- pakiety -------------------------------------------------------------------

    public function getPackageFields($vars = null)
    {
        Loader::loadHelpers($this, ['Html']);
        $fields = new ModuleFields();
        $catalog = ['packages' => [], 'templates' => [], 'locations' => []];
        $row = $this->rowFor(isset($vars->module_row) ? $vars->module_row : 0, isset($vars->module_group) ? $vars->module_group : '');
        if ($row) {
            try {
                $catalog = $this->api($row)->catalog();
            } catch (\Exception $e) {
                $fields->setHtml('<p class="text-danger">' . htmlspecialchars($e->getMessage()) . '</p>');
            }
        }
        $meta = isset($vars->meta) ? (array) $vars->meta : [];

        $packages = [];
        foreach ($catalog['packages'] as $p) {
            $packages[$p['slug']] = sprintf('%s (%d vCPU, %d MB RAM, %d GB)', $p['name'], $p['vcpu'], $p['ram_mb'], $p['disk_gb']);
        }
        $templates = [];
        foreach ($catalog['templates'] as $t) {
            $templates[$t['id']] = $t['name'];
        }
        $locations = ['' => Language::_('Virthub.config_any_location', true)];
        foreach ($catalog['locations'] as $l) {
            $locations[$l['id']] = $l['name'];
        }

        foreach ([
            'package' => ['Virthub.config_package', $packages],
            'template' => ['Virthub.config_template', $templates],
            'location' => ['Virthub.config_location', $locations],
        ] as $key => $def) {
            $label = $fields->label(Language::_($def[0], true), 'virthub_' . $key);
            $label->attach($fields->fieldSelect('meta[' . $key . ']', $def[1], isset($meta[$key]) ? $meta[$key] : null, ['id' => 'virthub_' . $key]));
            $fields->setField($label);
        }
        $label = $fields->label(Language::_('Virthub.config_domain', true), 'virthub_domain');
        $label->attach($fields->fieldText('meta[hostname_domain]', isset($meta['hostname_domain']) ? $meta['hostname_domain'] : 'vps.example.com', ['id' => 'virthub_domain']));
        $label->attach($fields->tooltip(Language::_('Virthub.config_domain_hint', true)));
        $fields->setField($label);

        return $fields;
    }

    public function addPackage(array $vars = null)
    {
        $this->Input->setRules([
            'meta[package]' => ['empty' => ['rule' => 'isEmpty', 'negate' => true, 'message' => Language::_('Virthub.!error.package', true)]],
            'meta[template]' => ['valid' => ['rule' => 'is_numeric', 'message' => Language::_('Virthub.!error.template', true)]],
        ]);
        if (!$this->Input->validates($vars)) {
            return;
        }
        $meta = [];
        foreach (['package', 'template', 'location', 'hostname_domain'] as $key) {
            $meta[] = ['key' => $key, 'value' => isset($vars['meta'][$key]) ? $vars['meta'][$key] : '', 'encrypted' => 0];
        }

        return $meta;
    }

    public function editPackage($package, array $vars = null)
    {
        return $this->addPackage($vars);
    }

    // --- pola usługi ------------------------------------------------------------------

    public function getClientAddFields($package, $vars = null)
    {
        return $this->serviceFields($package, $vars);
    }

    public function getAdminAddFields($package, $vars = null)
    {
        return $this->serviceFields($package, $vars);
    }

    private function serviceFields($package, $vars)
    {
        $fields = new ModuleFields();
        $templates = [];
        try {
            foreach ($this->api($this->rowFor($package->module_row, $package->module_group))->catalog()['templates'] as $t) {
                $templates[$t['id']] = $t['name'];
            }
        } catch (\Exception $e) {
            // Bez listy systemów zostaje domyślny z pakietu.
        }

        $hostname = $fields->label(Language::_('Virthub.checkout_hostname', true), 'virthub_hostname');
        $hostname->attach($fields->fieldText('virthub_hostname', isset($vars->virthub_hostname) ? $vars->virthub_hostname : (isset($vars->domain) ? $vars->domain : null), ['id' => 'virthub_hostname', 'placeholder' => 'vps1.example.com']));
        $fields->setField($hostname);
        if ($templates) {
            $os = $fields->label(Language::_('Virthub.checkout_os', true), 'virthub_template');
            $os->attach($fields->fieldSelect('virthub_template', $templates, isset($vars->virthub_template) ? $vars->virthub_template : $package->meta->template, ['id' => 'virthub_template']));
            $fields->setField($os);
        }
        $key = $fields->label(Language::_('Virthub.checkout_ssh_key', true), 'virthub_ssh_key');
        $key->attach($fields->fieldTextarea('virthub_ssh_key', isset($vars->virthub_ssh_key) ? $vars->virthub_ssh_key : null, ['id' => 'virthub_ssh_key', 'rows' => 3]));
        $fields->setField($key);

        return $fields;
    }

    public function validateService($package, array $vars = null)
    {
        $this->Input->setRules([
            'virthub_hostname' => ['format' => ['if_set' => true, 'rule' => [[$this, 'validateHostname']], 'message' => Language::_('Virthub.!error.hostname', true)]],
        ]);

        return $this->Input->validates($vars);
    }

    public function validateHostname($hostname)
    {
        return $hostname === '' || Client::hostname($hostname, 'x') === strtolower(trim($hostname));
    }

    // --- cykl życia ---------------------------------------------------------------------

    public function addService($package, array $vars = null, $parent_package = null, $parent_service = null, $status = 'pending')
    {
        $row = $this->getModuleRow();
        if (!$row) {
            $this->Input->setErrors(['module_row' => ['missing' => Language::_('Virthub.!error.module_row', true)]]);

            return;
        }
        $this->validateService($package, $vars);
        if ($this->Input->errors()) {
            return;
        }

        $reference = isset($vars['virthub_reference']) && $vars['virthub_reference'] !== '' ? $vars['virthub_reference'] : 'blesta-' . bin2hex(random_bytes(6));
        $hostname = Client::hostname(isset($vars['virthub_hostname']) ? $vars['virthub_hostname'] : '', 'vps' . substr($reference, 7), $package->meta->hostname_domain);
        $fields = [
            ['key' => 'virthub_reference', 'value' => $reference, 'encrypted' => 0],
            ['key' => 'virthub_hostname', 'value' => $hostname, 'encrypted' => 0],
        ];

        if (isset($vars['use_module']) && $vars['use_module'] === 'true') {
            Loader::loadModels($this, ['Clients']);
            $customer = $this->Clients->get(isset($vars['client_id']) ? $vars['client_id'] : 0, false);
            $request = [
                'billing_reference' => $reference,
                'customer_email' => $customer ? $customer->email : '',
                'customer_name' => $customer ? trim($customer->first_name . ' ' . $customer->last_name) : 'Blesta',
                'customer_reference' => 'blesta-client-' . (isset($vars['client_id']) ? $vars['client_id'] : ''),
                'package' => $package->meta->package,
                'template' => (int) (!empty($vars['virthub_template']) ? $vars['virthub_template'] : $package->meta->template),
                'hostname' => $hostname,
                'ssh_keys' => Client::parseSshKeys(isset($vars['virthub_ssh_key']) ? $vars['virthub_ssh_key'] : ''),
            ];
            if (!empty($package->meta->location)) {
                $request['location'] = (int) $package->meta->location;
            }
            $api = $this->api($row);
            try {
                $this->log($row->meta->panel_url . '|provision', serialize(array_merge($request, ['customer_email' => '***'])), 'input', true);
                $api->provision($request);
                $credentials = $api->credentials($reference);
                $service = $api->service($reference);
            } catch (\Exception $e) {
                $this->fail($row, 'provision', $e);

                return;
            }
            $fields[] = ['key' => 'virthub_ip', 'value' => (string) Client::primaryIp($service), 'encrypted' => 0];
            $fields[] = ['key' => 'virthub_username', 'value' => $credentials['username'], 'encrypted' => 0];
            $fields[] = ['key' => 'virthub_password', 'value' => (string) $credentials['password'], 'encrypted' => 1];
        }

        return $fields;
    }

    public function editService($package, $service, array $vars = [], $parent_package = null, $parent_service = null)
    {
        return null;
    }

    public function suspendService($package, $service, $parent_package = null, $parent_service = null)
    {
        return $this->call($package, $service, 'suspend', function (Client $api, $reference) {
            $api->suspend($reference);
        });
    }

    public function unsuspendService($package, $service, $parent_package = null, $parent_service = null)
    {
        return $this->call($package, $service, 'unsuspend', function (Client $api, $reference) {
            $api->unsuspend($reference);
        });
    }

    public function cancelService($package, $service, $parent_package = null, $parent_service = null)
    {
        return $this->call($package, $service, 'terminate', function (Client $api, $reference) {
            $api->terminate($reference);
        });
    }

    public function changeServicePackage($package_from, $package_to, $service, $parent_package = null, $parent_service = null)
    {
        if ($package_from->meta->package === $package_to->meta->package) {
            return null;
        }

        return $this->call($package_to, $service, 'resize', function (Client $api, $reference) use ($package_to) {
            try {
                $api->changePackage($reference, $package_to->meta->package);
            } catch (ClientException $e) {
                if ($e->status() === 409) {
                    throw new ClientException(Language::_('Virthub.resize_needs_stop', true) . ' (' . $e->getMessage() . ')', 409);
                }
                throw $e;
            }
        });
    }

    // --- zakładki ---------------------------------------------------------------------------

    public function getAdminTabs($package)
    {
        return ['tabActions' => Language::_('Virthub.actions', true)];
    }

    public function getClientTabs($package)
    {
        return ['tabClientActions' => ['name' => Language::_('Virthub.actions', true), 'icon' => 'fas fa-server']];
    }

    public function tabActions($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->actionsTab($package, $service, false, (array) $post);
    }

    public function tabClientActions($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->actionsTab($package, $service, true, (array) $post);
    }

    public function getClientServiceInfo($service, $package)
    {
        return $this->serviceInfo('client_service_info', $service, $package);
    }

    public function getAdminServiceInfo($service, $package)
    {
        return $this->serviceInfo('admin_service_info', $service, $package);
    }

    private function serviceInfo($view, $service, $package)
    {
        $this->view = $this->makeView($view);
        $this->view->set('service_fields', $this->serviceFieldsToObject($service->fields));

        return $this->view->fetch();
    }

    private function actionsTab($package, $service, $client, array $post)
    {
        $row = $this->getModuleRow($package->module_row);
        $fields = $this->serviceFieldsToObject($service->fields);
        $reference = isset($fields->virthub_reference) ? $fields->virthub_reference : '';
        $api = $this->api($row);
        $message = null;

        if (!empty($post['virthub_action'])) {
            try {
                $message = $this->perform($api, $service, $reference, $post);
            } catch (\Exception $e) {
                $this->Input->setErrors(['api' => ['internal' => $e->getMessage()]]);
            }
        }

        $this->view = $this->makeView('tab_actions');
        $this->view->set('client', $client);
        $this->view->set('message', $message);
        $this->view->set('service_fields', $fields);
        try {
            $data = $api->service($reference);
            $this->storePassword($service, $api, $reference);
            $templates = [];
            foreach ($api->catalog()['templates'] as $t) {
                if ($t['virtualization'] === $data['virtualization']) {
                    $templates[$t['id']] = $t['name'];
                }
            }
            $traffic = isset($data['traffic']) ? $data['traffic'] : [];
            $this->view->set('server', $data);
            $this->view->set('ips', Client::allIps($data));
            $this->view->set('templates', $templates);
            $this->view->set('traffic', Language::_('Virthub.traffic_of', true, Client::formatBytes(isset($traffic['used']) ? $traffic['used'] : 0), !empty($traffic['limit']) ? Client::formatBytes($traffic['limit']) : Language::_('Virthub.unlimited', true)));
            $this->view->set('traffic_percent', isset($traffic['percent']) ? $traffic['percent'] : null);
        } catch (\Exception $e) {
            $this->view->set('server', null);
            $this->view->set('error', Language::_('Virthub.error_unavailable', true, $e->getMessage()));
        }

        return $this->view->fetch();
    }

    /** Wykonuje akcję z formularza zakładki; zwraca komunikat dla użytkownika. */
    private function perform(Client $api, $service, $reference, array $post)
    {
        $action = (string) $post['virthub_action'];
        switch ($action) {
            case 'start':
            case 'stop':
            case 'reboot':
            case 'force-off':
                $api->power($reference, $action);

                return Language::_('Virthub.action_sent', true);
            case 'reset_password':
                $api->resetPassword($reference);

                return Language::_('Virthub.reset_password_done', true);
            case 'fetch_password':
                return Language::_($this->storePassword($service, $api, $reference) ? 'Virthub.password_saved' : 'Virthub.password_none', true);
            case 'reinstall':
                if (empty($post['confirm'])) {
                    throw new ClientException(Language::_('Virthub.reinstall_need_confirm', true), 422);
                }
                $api->reinstall($reference, (int) $post['template'], Client::parseSshKeys(isset($post['ssh_keys']) ? $post['ssh_keys'] : ''));

                return Language::_('Virthub.reinstall_started', true);
            case 'console':
                $this->redirect($api->consoleUrl($reference));
                // no break
            case 'open_panel':
                $this->redirect($api->ssoUrl($reference));
        }

        return null;
    }

    private function redirect($url)
    {
        header('Location: ' . $url);
        exit;
    }

    /** Zapisuje nowe hasło (po reinstalacji/resecie) w polach usługi. */
    private function storePassword($service, Client $api, $reference)
    {
        $credentials = $api->credentials($reference);
        if (empty($credentials['available'])) {
            return false;
        }
        Loader::loadModels($this, ['Services']);
        $this->Services->editField($service->id, ['key' => 'virthub_password', 'value' => $credentials['password'], 'encrypted' => 1]);
        $this->Services->editField($service->id, ['key' => 'virthub_username', 'value' => $credentials['username'], 'encrypted' => 0]);

        return true;
    }

    // --- pomocnicze -----------------------------------------------------------------------

    private function makeView($name)
    {
        $view = new View($name, 'default');
        $view->base_uri = $this->base_uri;
        $view->setDefaultView('components' . DS . 'modules' . DS . 'virthub' . DS);
        Loader::loadHelpers($view, ['Form', 'Html', 'Widget']);

        return $view;
    }

    private function api($row)
    {
        if (!$row) {
            throw new ClientException(Language::_('Virthub.!error.module_row', true));
        }

        $language = class_exists('Configure') ? (string) Configure::get('Blesta.language') : '';

        return new Client($row->meta->panel_url, $row->meta->api_token, 30, null, $language !== '' ? $language : null);
    }

    private function rowFor($module_row, $module_group = '')
    {
        if (!empty($module_group) && $module_group !== 'select') {
            $rows = $this->getModuleRows($module_group);

            return isset($rows[0]) ? $rows[0] : null;
        }
        if ($module_row > 0) {
            return $this->getModuleRow($module_row);
        }
        $rows = $this->getModuleRows();

        return isset($rows[0]) ? $rows[0] : null;
    }

    private function call($package, $service, $action, callable $fn)
    {
        $row = $this->getModuleRow($package->module_row);
        $fields = $this->serviceFieldsToObject($service->fields);
        if (empty($fields->virthub_reference)) {
            return null;
        }
        try {
            $this->log($row->meta->panel_url . '|' . $action, $fields->virthub_reference, 'input', true);
            $fn($this->api($row), $fields->virthub_reference);
        } catch (\Exception $e) {
            $this->fail($row, $action, $e);
        }

        return null;
    }

    private function fail($row, $action, \Exception $e)
    {
        $this->log(($row ? $row->meta->panel_url : 'virthub') . '|' . $action, $e->getMessage(), 'output', false);
        $message = $e instanceof ClientException && $e->status() === 404 ? Language::_('Virthub.error_not_found', true) : $e->getMessage();
        $this->Input->setErrors(['api' => ['internal' => $message]]);
    }
}
