<?php

/**
 * VirtHub — moduł serwerowy WHMCS (Setup → Products/Services → Servers, typ „VirtHub”).
 *
 * Zakładanie, zawieszanie, odwieszanie, usuwanie i zmiana pakietu VPS-a oraz
 * sterowanie z panelu klienta i administratora WHMCS: zasilanie, konsola,
 * reinstalacja, reset hasła, logowanie do VirtHub (SSO), zużycie transferu.
 *
 * Serwer w WHMCS: Hostname = adres panelu VirtHub (np. panel.example.com),
 * „Access Hash” albo „Password” = token API z zakresem „billing”, Secure = tak.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/VirtHubClient.php';
require_once __DIR__ . '/lib/Lang.php';

use VirtHub\Billing\Client;
use VirtHub\Billing\ClientException;
use VirtHub\Billing\Lang;
use WHMCS\Database\Capsule;

// --- pomocnicze ------------------------------------------------------------------

function virthub_locale()
{
    if (!empty($_SESSION['adminid']) && !empty($_SESSION['adminlang'])) {
        return $_SESSION['adminlang'];
    }
    if (!empty($_SESSION['Language'])) {
        return $_SESSION['Language'];
    }

    return isset($GLOBALS['CONFIG']['Language']) ? $GLOBALS['CONFIG']['Language'] : 'english';
}

function virthub_t($key, array $vars = [])
{
    return Lang::get(__DIR__ . '/lang', virthub_locale(), $key, $vars);
}

/** @return Client */
function virthub_client(array $params)
{
    $host = !empty($params['serverhostname']) ? $params['serverhostname'] : (isset($params['serverip']) ? $params['serverip'] : '');
    if (!preg_match('#^https?://#i', $host)) {
        $secure = !isset($params['serversecure']) || $params['serversecure'] === '' || $params['serversecure'] === true
            || $params['serversecure'] === 'on' || $params['serversecure'] === '1' || $params['serversecure'] === 1;
        $port = isset($params['serverport']) ? (int) $params['serverport'] : 0;
        $host = ($secure ? 'https://' : 'http://') . $host . ($port && !in_array($port, [80, 443], true) ? ':' . $port : '');
    }
    $token = !empty($params['serveraccesshash']) ? $params['serveraccesshash'] : (isset($params['serverpassword']) ? $params['serverpassword'] : '');

    return new Client($host, $token, 30, null, Lang::normalize(virthub_locale()));
}

function virthub_reference(array $params)
{
    return Client::reference('whmcs', $params['serviceid']);
}

/** Wartość opcji konfigurowalnej albo pola własnego o jednej z nazw (np. „Operating System”). */
function virthub_option(array $params, array $names)
{
    foreach (['configoptions', 'customfields'] as $group) {
        if (empty($params[$group]) || !is_array($params[$group])) {
            continue;
        }
        foreach ($params[$group] as $name => $value) {
            $clean = strtolower(trim(explode('|', (string) $name)[0]));
            foreach ($names as $wanted) {
                if ($clean === strtolower($wanted) && trim((string) $value) !== '') {
                    return trim((string) $value);
                }
            }
        }
    }

    return null;
}

function virthub_template_id(array $params)
{
    $chosen = virthub_option($params, ['os', 'template', 'operating system', 'system operacyjny', 'system']);
    if ($chosen !== null && preg_match('/^\s*(\d+)/', $chosen, $m)) {
        return (int) $m[1];
    }

    return (int) $params['configoption2'];
}

function virthub_log($action, $request, $response)
{
    if (function_exists('logModuleCall')) {
        logModuleCall('virthub', $action, $request, $response, '', []);
    }
}

function virthub_error(\Exception $e, $action, array $params = [])
{
    virthub_log($action, ['serviceid' => isset($params['serviceid']) ? $params['serviceid'] : null], $e->getMessage());
    if ($e instanceof ClientException && $e->status() === 404) {
        return virthub_t('error_not_found');
    }

    return $e->getMessage();
}

/** Zapisuje hasło, adresy i nazwę hosta w danych usługi WHMCS. */
function virthub_store(array $params, array $fields)
{
    $fields = array_filter($fields, function ($value) {
        return $value !== null && $value !== '';
    });
    if ($fields === [] || !function_exists('localAPI')) {
        return;
    }
    localAPI('UpdateClientProduct', ['serviceid' => $params['serviceid']] + $fields);
}

/** Odbiera nowe hasło (po założeniu, reinstalacji albo resecie), jeśli panel je ma. */
function virthub_sync_password(array $params, Client $client)
{
    $credentials = $client->credentials(virthub_reference($params));
    if (!empty($credentials['available']) && !empty($credentials['password'])) {
        virthub_store($params, ['servicepassword' => $credentials['password'], 'serviceusername' => $credentials['username']]);

        return true;
    }

    return false;
}

function virthub_sync_ips(array $params, array $service)
{
    $ips = Client::allIps($service);
    $primary = Client::primaryIp($service);
    virthub_store($params, [
        'dedicatedip' => $primary,
        'assignedips' => implode("\n", array_values(array_diff($ips, [$primary]))),
        'domain' => empty($params['domain']) && !empty($service['hostname']) ? $service['hostname'] : null,
    ]);
}

// --- metadane i konfiguracja ----------------------------------------------------------

function virthub_MetaData()
{
    return [
        'DisplayName' => 'VirtHub',
        'APIVersion' => '1.1',
        'RequiresServer' => true,
        'DefaultSSLPort' => '443',
        'DefaultNonSSLPort' => '80',
        'ServiceSingleSignOnLabel' => virthub_t('open_panel'),
        'AdminSingleSignOnLabel' => virthub_t('open_panel'),
    ];
}

function virthub_ConfigOptions()
{
    return [
        'package' => ['FriendlyName' => virthub_t('config_package'), 'Type' => 'text', 'Size' => '30', 'Loader' => 'virthub_LoaderPackages', 'SimpleMode' => true, 'Description' => virthub_t('config_package_hint')],
        'template' => ['FriendlyName' => virthub_t('config_template'), 'Type' => 'text', 'Size' => '30', 'Loader' => 'virthub_LoaderTemplates', 'SimpleMode' => true, 'Description' => virthub_t('config_template_hint')],
        'location' => ['FriendlyName' => virthub_t('config_location'), 'Type' => 'text', 'Size' => '30', 'Loader' => 'virthub_LoaderLocations', 'SimpleMode' => true, 'Description' => virthub_t('config_location_hint')],
        'hostname_domain' => ['FriendlyName' => virthub_t('config_domain'), 'Type' => 'text', 'Size' => '30', 'Default' => 'vps.example.com', 'SimpleMode' => true, 'Description' => virthub_t('config_domain_hint')],
    ];
}

function virthub_LoaderPackages(array $params)
{
    $out = [];
    foreach (virthub_client($params)->catalog()['packages'] as $p) {
        $out[$p['slug']] = sprintf('%s (%d vCPU, %d MB RAM, %d GB)', $p['name'], $p['vcpu'], $p['ram_mb'], $p['disk_gb']);
    }

    return $out;
}

function virthub_LoaderTemplates(array $params)
{
    $out = [];
    foreach (virthub_client($params)->catalog()['templates'] as $t) {
        $out[$t['id']] = $t['name'] . ($t['virtualization'] === 'lxc' ? ' (LXC)' : '');
    }

    return $out;
}

function virthub_LoaderLocations(array $params)
{
    $out = ['' => virthub_t('config_any_location')];
    foreach (virthub_client($params)->catalog()['locations'] as $l) {
        $out[$l['id']] = $l['name'];
    }

    return $out;
}

function virthub_TestConnection(array $params)
{
    try {
        virthub_client($params)->testConnection();

        return ['success' => true, 'error' => ''];
    } catch (\Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// --- cykl życia usługi -------------------------------------------------------------

function virthub_CreateAccount(array $params)
{
    try {
        $client = virthub_client($params);
        $details = isset($params['clientsdetails']) ? $params['clientsdetails'] : [];
        $name = trim((isset($details['firstname']) ? $details['firstname'] : '') . ' ' . (isset($details['lastname']) ? $details['lastname'] : ''));
        $request = [
            'billing_reference' => virthub_reference($params),
            'customer_email' => isset($details['email']) ? $details['email'] : '',
            'customer_name' => $name !== '' ? $name : (isset($details['email']) ? $details['email'] : 'WHMCS'),
            'customer_reference' => 'whmcs-client-' . $params['userid'],
            'package' => $params['configoption1'],
            'template' => virthub_template_id($params),
            'hostname' => Client::hostname($params['domain'], 'vps' . $params['serviceid'], $params['configoption4']),
            'ssh_keys' => Client::parseSshKeys(virthub_option($params, ['ssh key', 'ssh_key', 'klucz ssh', 'ssh'])),
        ];
        if (!empty($params['configoption3'])) {
            $request['location'] = (int) $params['configoption3'];
        }
        $response = $client->provision($request);
        virthub_log(__FUNCTION__, array_merge($request, ['customer_email' => '***']), $response);

        virthub_sync_password($params, $client);
        virthub_store($params, ['domain' => empty($params['domain']) ? $request['hostname'] : null]);
        try {
            virthub_sync_ips($params, $client->service(virthub_reference($params)));
        } catch (\Exception $e) {
            // Adresy uzupełnią się przy pierwszym otwarciu usługi.
        }
    } catch (\Exception $e) {
        return virthub_error($e, __FUNCTION__, $params);
    }

    return 'success';
}

function virthub_SuspendAccount(array $params)
{
    try {
        virthub_client($params)->suspend(virthub_reference($params), !empty($params['suspendreason']) ? $params['suspendreason'] : null);
    } catch (\Exception $e) {
        return virthub_error($e, __FUNCTION__, $params);
    }

    return 'success';
}

function virthub_UnsuspendAccount(array $params)
{
    try {
        virthub_client($params)->unsuspend(virthub_reference($params));
    } catch (\Exception $e) {
        return virthub_error($e, __FUNCTION__, $params);
    }

    return 'success';
}

function virthub_TerminateAccount(array $params)
{
    try {
        virthub_client($params)->terminate(virthub_reference($params));
    } catch (\Exception $e) {
        return virthub_error($e, __FUNCTION__, $params);
    }

    return 'success';
}

function virthub_ChangePackage(array $params)
{
    try {
        virthub_client($params)->changePackage(virthub_reference($params), $params['configoption1']);
    } catch (ClientException $e) {
        if ($e->status() === 409) {
            return virthub_t('resize_needs_stop') . ' (' . $e->getMessage() . ')';
        }

        return virthub_error($e, __FUNCTION__, $params);
    } catch (\Exception $e) {
        return virthub_error($e, __FUNCTION__, $params);
    }

    return 'success';
}

// --- sterowanie (przyciski administratora i klienta) ------------------------------------

function virthub_AdminCustomButtonArray()
{
    return [
        virthub_t('start') => 'start',
        virthub_t('stop') => 'stop',
        virthub_t('reboot') => 'reboot',
        virthub_t('force_off') => 'forceoff',
        virthub_t('reset_password') => 'resetpassword',
        virthub_t('show_password') => 'fetchpassword',
    ];
}

function virthub_ClientAreaCustomButtonArray()
{
    return [
        virthub_t('start') => 'start',
        virthub_t('stop') => 'stop',
        virthub_t('reboot') => 'reboot',
        virthub_t('force_off') => 'forceoff',
        virthub_t('reset_password') => 'resetpassword',
    ];
}

function virthub_power(array $params, $action)
{
    try {
        virthub_client($params)->power(virthub_reference($params), $action);
    } catch (\Exception $e) {
        return virthub_error($e, 'power_' . $action, $params);
    }

    return 'success';
}

function virthub_start(array $params)
{
    return virthub_power($params, 'start');
}

function virthub_stop(array $params)
{
    return virthub_power($params, 'stop');
}

function virthub_reboot(array $params)
{
    return virthub_power($params, 'reboot');
}

function virthub_forceoff(array $params)
{
    return virthub_power($params, 'force-off');
}

function virthub_resetpassword(array $params)
{
    try {
        virthub_client($params)->resetPassword(virthub_reference($params));
    } catch (\Exception $e) {
        return virthub_error($e, __FUNCTION__, $params);
    }

    return 'success';
}

function virthub_fetchpassword(array $params)
{
    try {
        return virthub_sync_password($params, virthub_client($params)) ? 'success' : virthub_t('password_none');
    } catch (\Exception $e) {
        return virthub_error($e, __FUNCTION__, $params);
    }
}

/** Reinstalacja z formularza w panelu klienta (POST: template, confirm, ssh_keys). */
function virthub_reinstall(array $params)
{
    if (empty($_POST['confirm'])) {
        return virthub_t('reinstall_need_confirm');
    }
    $template = isset($_POST['template']) ? (int) $_POST['template'] : 0;
    try {
        virthub_client($params)->reinstall(virthub_reference($params), $template, Client::parseSshKeys(isset($_POST['ssh_keys']) ? $_POST['ssh_keys'] : ''));
    } catch (\Exception $e) {
        return virthub_error($e, __FUNCTION__, $params);
    }

    return 'success';
}

/** Konsola: przekierowanie na jednorazowy adres konsoli w VirtHub. */
function virthub_console(array $params)
{
    try {
        $url = virthub_client($params)->consoleUrl(virthub_reference($params));
    } catch (ClientException $e) {
        return $e->status() === 409 ? virthub_t('console_unavailable') : virthub_error($e, __FUNCTION__, $params);
    }
    header('Location: ' . $url);
    exit;
}

function virthub_ServiceSingleSignOn(array $params)
{
    try {
        return ['success' => true, 'redirectTo' => virthub_client($params)->ssoUrl(virthub_reference($params))];
    } catch (\Exception $e) {
        return ['success' => false, 'errorMsg' => virthub_error($e, __FUNCTION__, $params)];
    }
}

function virthub_AdminSingleSignOn(array $params)
{
    return virthub_ServiceSingleSignOn($params);
}

// --- widoki ----------------------------------------------------------------------

/** Dane do szablonów: stan usługi, zasoby, systemy do reinstalacji. */
function virthub_view_data(array $params)
{
    $client = virthub_client($params);
    $reference = virthub_reference($params);
    $service = $client->service($reference);
    try {
        virthub_sync_password($params, $client);
    } catch (\Exception $e) {
        // Brak hasła do odebrania — nic nie robimy.
    }
    $templates = [];
    try {
        foreach ($client->catalog()['templates'] as $t) {
            if ($t['virtualization'] === $service['virtualization']) {
                $templates[] = $t;
            }
        }
    } catch (\Exception $e) {
        // Lista systemów jest dodatkiem — bez niej reszta działa.
    }
    $traffic = isset($service['traffic']) ? $service['traffic'] : [];

    return [
        'service' => $service,
        'state_label' => virthub_t('state.' . $service['state']),
        'primary_ip' => Client::primaryIp($service),
        'ips' => Client::allIps($service),
        'traffic_text' => virthub_t('traffic_of', [
            'used' => Client::formatBytes(isset($traffic['used']) ? $traffic['used'] : 0),
            'limit' => !empty($traffic['limit']) ? Client::formatBytes($traffic['limit']) : virthub_t('unlimited'),
        ]),
        'traffic_percent' => isset($traffic['percent']) ? $traffic['percent'] : null,
        'templates' => $templates,
        'running' => $service['state'] === 'running',
        'lang' => Lang::strings(__DIR__ . '/lang', virthub_locale()),
    ];
}

function virthub_ClientArea(array $params)
{
    try {
        return [
            'tabOverviewModuleOutputTemplate' => 'templates/overview',
            'templateVariables' => ['vh' => virthub_view_data($params)],
        ];
    } catch (\Exception $e) {
        return [
            'tabOverviewModuleOutputTemplate' => 'templates/error',
            'templateVariables' => ['vhError' => virthub_t('error_unavailable', ['error' => virthub_error($e, __FUNCTION__, $params)])],
        ];
    }
}

function virthub_AdminServicesTabFields(array $params)
{
    try {
        $data = virthub_view_data($params);
        $s = $data['service'];
        $html = '<strong>' . htmlspecialchars($data['state_label']) . '</strong>'
            . ($s['state'] === 'building' ? ' — ' . (int) $s['build_progress'] . '%' : '')
            . ($s['suspended'] ? ' · ' . htmlspecialchars(virthub_t('state.suspended')) : '')
            . '<br>' . htmlspecialchars($s['hostname']) . ' · ' . htmlspecialchars(implode(', ', $data['ips']))
            . '<br>' . (int) $s['resources']['vcpu'] . ' vCPU · ' . (int) $s['resources']['ram_mb'] . ' MB RAM · ' . (int) $s['resources']['disk_gb'] . ' GB'
            . (isset($s['os']['name']) ? ' · ' . htmlspecialchars($s['os']['name']) : '')
            . '<br>' . htmlspecialchars(virthub_t('traffic')) . ': ' . htmlspecialchars($data['traffic_text']);
    } catch (\Exception $e) {
        $html = '<span style="color:#c00">' . htmlspecialchars(virthub_error($e, __FUNCTION__, $params)) . '</span>';
    }

    return ['VirtHub' => $html];
}

/** Codzienne zużycie transferu (Utilities → Automation, „Update Usage”). */
function virthub_UsageUpdate(array $params)
{
    $client = virthub_client($params);
    $services = Capsule::table('tblhosting')->where('server', $params['serverid'])
        ->whereIn('domainstatus', ['Active', 'Suspended'])->get(['id']);
    foreach ($services as $row) {
        try {
            $traffic = $client->service(Client::reference('whmcs', $row->id))['traffic'];
            Capsule::table('tblhosting')->where('id', $row->id)->update([
                'bwusage' => round($traffic['used'] / 1000000),
                'bwlimit' => $traffic['limit'] ? round($traffic['limit'] / 1000000) : 0,
                'lastupdate' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Exception $e) {
            virthub_log(__FUNCTION__, ['serviceid' => $row->id], $e->getMessage());
        }
    }

    return 'success';
}
