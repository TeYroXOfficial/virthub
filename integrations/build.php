<?php

/*
 * Synchronizuje wspólne pliki (klient API, tłumaczenia) do modułów WHMCS,
 * Blesta i Paymenter. Z --zip buduje też paczki do wgrania w dist/.
 *
 *   php integrations/build.php          # kopiuje pliki
 *   php integrations/build.php --check  # kończy się błędem, gdy kopie są nieaktualne (CI)
 *   php integrations/build.php --zip    # + dist/virthub-{whmcs,blesta,paymenter}.zip
 */

$root = __DIR__;
$shared = $root . '/shared';
$check = in_array('--check', $argv, true);
$zip = in_array('--zip', $argv, true);

$targets = [
    'whmcs' => $root . '/whmcs/modules/servers/virthub',
    'paymenter' => $root . '/paymenter/extensions/Servers/VirtHub',
    'blesta' => $root . '/blesta/components/modules/virthub',
];

/** @return array<string, string> plik docelowy => treść */
function virthub_build_files(string $shared, array $targets): array
{
    $files = [];
    foreach ($targets as $name => $dir) {
        $files[$dir . '/lib/VirtHubClient.php'] = file_get_contents($shared . '/VirtHubClient.php');
        if ($name === 'blesta') {
            foreach (['en' => 'en_us', 'pl' => 'pl_pl'] as $code => $blesta) {
                $files[$dir . '/language/' . $blesta . '/virthub.php'] = virthub_blesta_lang(require $shared . '/lang/' . $code . '.php');
            }
            continue;
        }
        $files[$dir . '/lib/Lang.php'] = file_get_contents($shared . '/Lang.php');
        foreach (['en', 'pl'] as $code) {
            $files[$dir . '/lang/' . $code . '.php'] = file_get_contents($shared . '/lang/' . $code . '.php');
        }
    }

    return $files;
}

/** Blesta: klucze „Virthub.…”, błędy „Virthub.!error.…”, parametry jako %1$s. */
function virthub_blesta_lang(array $strings): string
{
    $out = "<?php\n\n// Wygenerowane przez integrations/build.php z integrations/shared/lang — nie edytuj ręcznie.\n\n";
    $strings += ['name' => $strings['module_name'], 'description' => $strings['module_description']];
    foreach ($strings as $key => $text) {
        $key = strpos($key, 'error.') === 0 ? '!' . $key : $key;
        $i = 0;
        $text = str_replace('%', '%%', $text);
        $text = preg_replace_callback('/:[a-z_]+/', function () use (&$i) {
            return '%' . (++$i) . '$s';
        }, $text);
        $out .= '$lang[' . var_export('Virthub.' . $key, true) . '] = ' . var_export($text, true) . ";\n";
    }

    return $out;
}

$stale = [];
foreach (virthub_build_files($shared, $targets) as $path => $content) {
    if (is_file($path) && file_get_contents($path) === $content) {
        continue;
    }
    if ($check) {
        $stale[] = substr($path, strlen($root) + 1);
        continue;
    }
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }
    file_put_contents($path, $content);
    echo 'updated ' . substr($path, strlen($root) + 1) . "\n";
}
if ($stale) {
    fwrite(STDERR, "Nieaktualne kopie (uruchom php integrations/build.php):\n  " . implode("\n  ", $stale) . "\n");
    exit(1);
}

if ($zip) {
    @mkdir($root . '/dist', 0755, true);
    $bases = ['whmcs' => $root . '/whmcs', 'paymenter' => $root . '/paymenter', 'blesta' => $root . '/blesta'];
    foreach ($bases as $name => $base) {
        $archive = new ZipArchive();
        $file = $root . '/dist/virthub-' . $name . '.zip';
        $archive->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $archive->addFile($f->getPathname(), substr($f->getPathname(), strlen($base) + 1));
        }
        $archive->close();
        echo 'built dist/virthub-' . $name . ".zip\n";
    }
}
