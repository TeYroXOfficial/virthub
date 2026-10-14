<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Billing → Moduły: paczki modułów WHMCS, Blesta i Paymenter (z katalogu
 * integrations/ repozytorium) z instrukcją instalacji.
 */
class BillingModulesController extends Controller
{
    /** system => [katalog źródłowy w integrations/, katalog docelowy w systemie] */
    public const SYSTEMS = [
        'whmcs' => ['whmcs', 'modules/servers/virthub'],
        'blesta' => ['blesta', 'components/modules/virthub'],
        'paymenter' => ['paymenter', 'extensions/Servers/VirtHub'],
    ];

    public static function root(): string
    {
        return (string) config('virthub.integrations_path', base_path('../integrations'));
    }

    public function index(): View
    {
        $available = collect(self::SYSTEMS)->map(fn (array $s) => is_dir(self::root().'/'.$s[0].'/'.$s[1]));

        return view('panel.admin.billing-modules', [
            'available' => $available,
            'panelUrl' => url('/'),
            'version' => $this->version(),
        ]);
    }

    public function download(string $system): BinaryFileResponse
    {
        abort_unless(isset(self::SYSTEMS[$system]), 404);
        [$dir] = self::SYSTEMS[$system];
        $base = realpath(self::root().'/'.$dir);
        abort_if($base === false, 404, __('Brak katalogu integrations/ — zaktualizuj panel z repozytorium.'));

        $file = tempnam(sys_get_temp_dir(), 'vh-module-');
        $zip = new \ZipArchive;
        $zip->open($file, \ZipArchive::OVERWRITE);
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $entry) {
            if ($entry->isFile()) {
                $zip->addFile($entry->getPathname(), str_replace('\\', '/', substr($entry->getPathname(), strlen($base) + 1)));
            }
        }
        $zip->close();

        return response()->download($file, 'virthub-'.$system.'-'.$this->version().'.zip', ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
    }

    private function version(): string
    {
        $client = @file_get_contents(self::root().'/shared/VirtHubClient.php');

        return $client && preg_match("/const VERSION = '([^']+)'/", $client, $m) ? $m[1] : '1.0.0';
    }
}
