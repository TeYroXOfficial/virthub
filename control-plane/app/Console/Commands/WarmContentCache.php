<?php

namespace App\Console\Commands;

use App\Domain\Apps\Content\ContentException;
use App\Domain\Apps\Content\ContentManager;
use App\Domain\Apps\Content\Loaders;
use App\Domain\Apps\Content\ServerProfile;
use App\Models\AppServer;
use Illuminate\Console\Command;

/**
 * Rozgrzewa cache katalogów modpacków, pluginów i modów, żeby klienci nie
 * czekali na zewnętrzne serwisy: pierwsze strony list, szczegóły i wersje
 * najpopularniejszych modpacków oraz katalogi dodatków dla wersji gry, które
 * faktycznie mają serwery klientów.
 */
class WarmContentCache extends Command
{
    protected $signature = 'virthub:warm-content {--top=12 : Ile najpopularniejszych modpacków rozgrzać ze szczegółami}';

    protected $description = 'Rozgrzewa cache katalogów modpacków, pluginów i modów';

    public function handle(ContentManager $content): int
    {
        $top = max(0, (int) $this->option('top'));
        $warmed = 0;
        $try = function (callable $fn) use (&$warmed): mixed {
            try {
                $result = $fn();
                $warmed++;

                return $result;
            } catch (ContentException|\Illuminate\Http\Client\ConnectionException $e) {
                $this->warn($e->getMessage());

                return null;
            }
        };

        $try(fn () => Loaders::mojangVersions());

        // Modpacki: pierwsza strona każdego źródła + szczegóły najpopularniejszych.
        $noServer = new ServerProfile(new AppServer);
        foreach (array_keys($content->sources('modpack')) as $source) {
            $list = $try(fn () => $content->search('modpack', $source, '', 1, $noServer));
            foreach (array_slice($list['items'] ?? [], 0, $top) as $item) {
                $try(fn () => $content->project('modpack', $source, $item['id']));
                $try(fn () => $content->versions('modpack', $source, $item['id'], $noServer));
            }
        }

        // Pluginy i mody: katalogi dla wersji gry i platform serwerów klientów.
        $seen = [];
        AppServer::query()->whereNotNull('minecraft')->with('egg')->each(function (AppServer $app) use ($content, $try, &$seen) {
            $profile = new ServerProfile($app);
            $kind = $profile->addonKind();
            $mc = $profile->gameVersion(false);
            $key = $kind.'|'.$mc.'|'.implode(',', $profile->loaders());
            if ($kind === null || $mc === null || isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            foreach (array_keys($content->sources($kind)) as $source) {
                $try(fn () => $content->search($kind, $source, '', 1, $profile));
            }
        });

        $this->info("Rozgrzano {$warmed} pozycji cache.");

        return self::SUCCESS;
    }
}
