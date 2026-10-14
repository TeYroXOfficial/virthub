<?php

namespace Tests\Feature;

use App\Http\Controllers\Web\BillingModulesController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class BillingModulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_strona_modulow_tylko_dla_administratora(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->actingAs($admin)->get(route('panel.admin.billing-modules'))->assertOk()
            ->assertSee('WHMCS')->assertSee('Blesta')->assertSee('Paymenter')
            ->assertSee(route('panel.admin.billing-modules.download', 'whmcs'));

        $this->actingAs(User::factory()->create())->get(route('panel.admin.billing-modules'))->assertForbidden();
    }

    public function test_paczki_zawieraja_modul_i_wspolny_klient(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $expected = [
            'whmcs' => ['modules/servers/virthub/virthub.php', 'modules/servers/virthub/lib/VirtHubClient.php', 'modules/servers/virthub/templates/overview.tpl', 'modules/servers/virthub/lang/pl.php'],
            'blesta' => ['components/modules/virthub/virthub.php', 'components/modules/virthub/config.json', 'components/modules/virthub/language/pl_pl/virthub.php', 'components/modules/virthub/views/default/tab_actions.pdt'],
            'paymenter' => ['extensions/Servers/VirtHub/VirtHub.php', 'extensions/Servers/VirtHub/routes.php', 'extensions/Servers/VirtHub/resources/views/control.blade.php'],
        ];
        foreach ($expected as $system => $files) {
            $response = $this->actingAs($admin)->get(route('panel.admin.billing-modules.download', $system))->assertOk();
            $path = $response->baseResponse->getFile()->getPathname();
            $zip = new \ZipArchive;
            $this->assertTrue($zip->open($path) === true, $system);
            foreach ($files as $file) {
                $this->assertNotFalse($zip->locateName($file), "$system: $file");
            }
            $zip->close();
        }
        $this->actingAs($admin)->get('/panel/admin/billing-modules/hostbill.zip')->assertNotFound();
    }

    public function test_kopie_wspolnych_plikow_sa_aktualne_i_php_poprawny(): void
    {
        $root = BillingModulesController::root();
        $check = Process::run(['php', $root.'/build.php', '--check']);
        $this->assertTrue($check->successful(), $check->errorOutput());

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (preg_match('/\.(php|pdt)$/', $file->getFilename())) {
                $lint = Process::run(['php', '-l', $file->getPathname()]);
                $this->assertTrue($lint->successful(), $lint->output());
            }
        }

        $en = require $root.'/shared/lang/en.php';
        $pl = require $root.'/shared/lang/pl.php';
        $this->assertSame([], array_diff_key($en, $pl));
        $this->assertSame([], array_diff_key($pl, $en));
        foreach ($en as $key => $text) {
            preg_match_all('/:[a-z_]+/', $text, $a);
            preg_match_all('/:[a-z_]+/', $pl[$key], $b);
            $this->assertEqualsCanonicalizing($a[0], $b[0], $key);
        }
    }
}
