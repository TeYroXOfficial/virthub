<?php

namespace App\Domain\Apps;

use App\Domain\Agent\AgentClient;
use App\Domain\Agent\AgentException;
use App\Jobs\InstallAppJob;
use App\Models\AppEgg;
use App\Models\AppJob;
use App\Models\AppPlan;
use App\Models\AppServer;
use App\Models\AuditLog;
use App\Models\Hypervisor;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Cykl życia aplikacji: zamówienie (węzeł, porty, instalacja), zasilanie,
 * ustawienia uruchamiania, reinstalacja, zawieszenie i usunięcie.
 */
class AppProvisioner
{
    public function __construct(private readonly AppPorts $ports) {}

    // --- zamówienie -----------------------------------------------------------

    /** @param  array<string, string>  $variables */
    public function order(User $user, AppEgg $egg, AppPlan $plan, string $name, ?string $image = null, array $variables = [], ?Hypervisor $node = null): AppServer
    {
        if (! $egg->is_active || ! $plan->is_active) {
            throw new \DomainException(__('Wybrany szablon albo plan nie jest dostępny.'));
        }

        $limit = (int) config('virthub.limits.apps_per_customer', 10);
        if (! $user->isStaff() && $limit > 0 && AppServer::query()->where('user_id', $user->id)->count() >= $limit) {
            throw new \DomainException(__('Osiągnięto limit :limit aplikacji na koncie.', ['limit' => $limit]));
        }

        $image ??= $egg->defaultImage();
        if (! in_array($image, $egg->images(), true)) {
            throw new \DomainException(__('Wybrany obraz nie należy do tego szablonu.'));
        }

        $app = DB::transaction(function () use ($user, $egg, $plan, $name, $image, $variables, $node) {
            $node = $node
                ? Hypervisor::query()->lockForUpdate()->findOrFail($node->id)
                : $this->pickNode($plan);

            if ($node === null || ! $this->fits($node, $plan)) {
                throw new \DomainException(__('Żaden węzeł nie ma teraz miejsca na tę aplikację. Spróbuj później albo wybierz mniejszy plan.'));
            }

            $app = AppServer::query()->create([
                'user_id' => $user->id,
                'hypervisor_id' => $node->id,
                'app_egg_id' => $egg->id,
                'app_plan_id' => $plan->id,
                'name' => $name,
                'docker_image' => $image,
                'environment' => $variables,
                'memory_mb' => $plan->memory_mb,
                'cpu_percent' => $plan->cpu_percent,
                'disk_mb' => $plan->disk_mb,
                'status' => AppServer::STATUS_INSTALLING,
            ]);
            $this->ports->allocate($app, $node, max(1, $plan->ports));

            return $app;
        });

        AuditLog::record('app.ordered', $app, ['egg' => $egg->name, 'plan' => $plan->name, 'node' => $app->hypervisor?->name], $user);
        $this->dispatch($app, 'install', $user);

        return $app->refresh();
    }

    /** Węzeł z aplikacjami i największą ilością wolnej pamięci. */
    public function pickNode(AppPlan $plan): ?Hypervisor
    {
        return Hypervisor::query()
            ->where('apps_enabled', true)
            ->where('status', Hypervisor::STATUS_ONLINE)
            ->lockForUpdate()
            ->get()
            ->filter(fn (Hypervisor $node) => $node->acceptsApps() && $this->fits($node, $plan))
            ->sortByDesc(fn (Hypervisor $node) => $this->freeMemory($node))
            ->first();
    }

    public function fits(Hypervisor $node, AppPlan $plan): bool
    {
        return $node->acceptsApps()
            && $this->freeMemory($node) >= $plan->memory_mb
            && $this->freeDisk($node) >= $plan->disk_mb
            && $this->ports->freeCount($node) >= max(1, $plan->ports);
    }

    /** Pamięć węzła po odjęciu maszyn i aplikacji (MB). */
    public function freeMemory(Hypervisor $node): int
    {
        $apps = (int) AppServer::query()->where('hypervisor_id', $node->id)->sum('memory_mb');

        return $node->ram_mb_total - $node->ram_mb_used - $apps;
    }

    public function freeDisk(Hypervisor $node): int
    {
        $apps = (int) AppServer::query()->where('hypervisor_id', $node->id)->sum('disk_mb');

        return ($node->disk_gb_total - $node->disk_gb_used) * 1024 - $apps;
    }

    // --- instalacja --------------------------------------------------------------

    public function reinstall(AppServer $app, ?User $actor = null): AppJob
    {
        $this->assertUsable($app, allowFailed: true);
        $app->forceFill(['status' => AppServer::STATUS_INSTALLING, 'status_message' => null])->save();
        AuditLog::record('app.reinstall', $app, [], $actor);

        return $this->dispatch($app, 'reinstall', $actor);
    }

    private function dispatch(AppServer $app, string $action, ?User $actor): AppJob
    {
        $job = AppJob::query()->create([
            'app_server_id' => $app->id,
            'user_id' => $actor?->id,
            'action' => $action,
            'status' => AppJob::STATUS_QUEUED,
        ]);
        InstallAppJob::dispatch($job->id);

        return $job;
    }

    // --- zasilanie i konsola ------------------------------------------------------

    public function power(AppServer $app, string $action, ?User $actor = null): array
    {
        $this->assertUsable($app);
        if (in_array($action, ['start', 'restart'], true)) {
            // Start zawsze z aktualnymi ustawieniami panelu (egg, zmienne, zasoby) —
            // węzeł mógł mieć starszą specyfikację, np. po poprawce eggu.
            $this->pushSpec($app);
        }
        $state = $this->client($app)->appPower($app->uuid, $action);
        AuditLog::record('app.power', $app, ['action' => $action], $actor);

        return $state;
    }

    public function command(AppServer $app, string $command): void
    {
        $this->assertUsable($app);
        $this->client($app)->appCommand($app->uuid, $command);
    }

    // --- ustawienia ----------------------------------------------------------------

    /** @param  array<string, string>  $variables */
    public function updateStartup(AppServer $app, ?string $image, array $variables, ?User $actor = null): bool
    {
        if ($image !== null && ! in_array($image, $app->egg->images(), true)) {
            throw new \DomainException(__('Wybrany obraz nie należy do tego szablonu.'));
        }

        $app->forceFill([
            'docker_image' => $image ?? $app->docker_image,
            'environment' => array_merge($app->environment ?? [], $variables),
        ])->save();
        AuditLog::record('app.startup', $app, ['image' => $app->docker_image, 'variables' => array_keys($variables)], $actor);

        return $this->pushSpec($app);
    }

    public function updateResources(AppServer $app, int $memoryMb, int $cpuPercent, int $diskMb, ?User $actor = null): bool
    {
        $app->forceFill(['memory_mb' => $memoryMb, 'cpu_percent' => $cpuPercent, 'disk_mb' => $diskMb])->save();
        AuditLog::record('app.resources', $app, compact('memoryMb', 'cpuPercent', 'diskMb'), $actor);

        return $this->pushSpec($app);
    }

    /** Zwolnienie z ochrony przed nadużyciami (fałszywy alarm) — węzeł dostaje je w specyfikacji. */
    public function setAbuseExempt(AppServer $app, bool $exempt, ?User $actor = null): void
    {
        $app->forceFill(['abuse_exempt' => $exempt])->save();
        AuditLog::record('app.abuse_exempt', $app, ['exempt' => $exempt], $actor);

        $this->pushSpec($app);
    }

    /** Wysyła nową specyfikację na węzeł. Zwraca true, gdy zmiana wymaga restartu. */
    private function pushSpec(AppServer $app): bool
    {
        if (! $app->isReady() || $app->hypervisor === null) {
            return false; // trafi na węzeł przy instalacji
        }

        return (bool) ($this->client($app)->appUpdate($app->uuid, AppPayload::spec($app))['restart_required'] ?? false);
    }

    // --- zawieszenie i usunięcie ----------------------------------------------------

    public function suspend(AppServer $app, string $reason, ?User $actor = null): void
    {
        $app->forceFill(['suspended_at' => now(), 'suspension_reason' => $reason])->save();
        AuditLog::record('app.suspend', $app, ['reason' => $reason], $actor);

        if ($app->isReady() && $app->hypervisor) {
            try {
                $this->client($app)->appPower($app->uuid, 'stop');
            } catch (AgentException) {
                // Węzeł niedostępny — aplikacja i tak nie wystartuje, dopóki zawieszona.
            }
        }
    }

    public function unsuspend(AppServer $app, ?User $actor = null): void
    {
        $app->forceFill(['suspended_at' => null, 'suspension_reason' => null])->save();
        AuditLog::record('app.unsuspend', $app, [], $actor);
    }

    /**
     * Usuwa aplikację z węzła (kontener i pliki), a potem z panelu. `$panelOnly`
     * pomija węzeł — dla aplikacji, których węzeł już nie istnieje.
     */
    public function destroy(AppServer $app, ?User $actor = null, bool $panelOnly = false): void
    {
        if (! $panelOnly && $app->hypervisor !== null) {
            $this->client($app)->appDelete($app->uuid);
        }

        AuditLog::record('app.delete', $app, ['name' => $app->name, 'panel_only' => $panelOnly], $actor);
        DB::transaction(function () use ($app) {
            $this->ports->release($app);
            $app->delete();
        });
    }

    // --- pomocnicze ------------------------------------------------------------------

    public function client(AppServer $app): AgentClient
    {
        if ($app->hypervisor === null) {
            throw new \DomainException(__('Aplikacja nie jest przypisana do węzła.'));
        }

        return new AgentClient($app->hypervisor);
    }

    public function assertUsable(AppServer $app, bool $allowFailed = false): void
    {
        if ($app->isSuspended()) {
            throw new \DomainException(__('Aplikacja jest zawieszona. Skontaktuj się z obsługą.'));
        }
        if ($app->isInstalling()) {
            throw new \DomainException(__('Aplikacja się instaluje — poczekaj na koniec instalacji.'));
        }
        if (! $allowFailed && ! $app->isReady()) {
            throw new \DomainException(__('Instalacja aplikacji nie powiodła się — uruchom ją ponownie w Ustawieniach.'));
        }
        if ($app->hypervisor === null) {
            throw new \DomainException(__('Aplikacja nie jest przypisana do węzła.'));
        }
    }
}
