<?php

namespace App\Domain\Network;

use App\Domain\Provisioning\ServerProvisioner;
use App\Models\AuditLog;
use App\Models\FirewallRule;
use App\Models\Server;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Zapora maszyny — wspólna dla panelu klienta, administracji i API.
 *
 * Każda zmiana zapisuje stan w bazie (źródło prawdy) i zleca agentowi
 * podmianę całego łańcucha maszyny w jednej transakcji nftables.
 *
 * Kto co może:
 *  - klient: swoje reguły i politykę, dopóki administrator nie zablokował zapory;
 *  - administrator: wszystko, w tym reguły administratora (sprawdzane przed
 *    regułami klienta, dla klienta tylko do odczytu) i blokadę zmian.
 */
class Firewall
{
    public const MAX_RULES = 50;

    /** Gotowe reguły do dodania jednym kliknięciem. */
    public const PRESETS = [
        'ssh' => ['label' => 'SSH (22)', 'rules' => [['protocol' => 'tcp', 'port_from' => 22, 'comment' => 'SSH']]],
        'web' => ['label' => 'HTTP/HTTPS', 'rules' => [
            ['protocol' => 'tcp', 'port_from' => 80, 'comment' => 'HTTP'],
            ['protocol' => 'tcp', 'port_from' => 443, 'comment' => 'HTTPS'],
        ]],
        'ping' => ['label' => 'Ping (ICMP)', 'rules' => [['protocol' => 'icmp', 'comment' => 'Ping']]],
        'rdp' => ['label' => 'RDP (3389)', 'rules' => [['protocol' => 'tcp', 'port_from' => 3389, 'comment' => 'Pulpit zdalny']]],
        'mail' => ['label' => 'Poczta', 'rules' => [
            ['protocol' => 'tcp', 'port_from' => 25, 'comment' => 'SMTP'],
            ['protocol' => 'tcp', 'port_from' => 587, 'comment' => 'SMTP submission'],
            ['protocol' => 'tcp', 'port_from' => 993, 'comment' => 'IMAPS'],
        ]],
    ];

    public function __construct(private readonly ServerProvisioner $provisioner) {}

    /** @return array<string, mixed> */
    public static function ruleRules(): array
    {
        return [
            'action' => ['required', Rule::in(['accept', 'drop'])],
            'direction' => ['required', Rule::in(['in', 'out'])],
            'protocol' => ['required', Rule::in(['tcp', 'udp', 'icmp', 'any'])],
            'port_from' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'port_to' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'source' => ['nullable', 'string', 'max:64'],
            'comment' => ['nullable', 'string', 'max:120'],
            'admin_rule' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, mixed> */
    public static function policyRules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'inbound' => ['required', Rule::in(['accept', 'drop'])],
            'outbound' => ['required', Rule::in(['accept', 'drop'])],
            'locked' => ['sometimes', 'boolean'],
        ];
    }

    public function canManage(User $user, Server $server): bool
    {
        return $user->isStaff() || ! $server->firewall_locked;
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws AuthorizationException|ValidationException
     */
    public function updatePolicy(Server $server, User $actor, array $data): void
    {
        $this->assertCanManage($actor, $server);

        $attributes = [
            'firewall_enabled' => (bool) $data['enabled'],
            'firewall_inbound' => $data['inbound'],
            'firewall_outbound' => $data['outbound'],
        ];

        if ($actor->isStaff() && array_key_exists('locked', $data)) {
            $attributes['firewall_locked'] = (bool) $data['locked'];
        }

        $server->forceFill($attributes)->save();
        AuditLog::record('firewall.policy', $server, $attributes, $actor);
        $this->sync($server, $actor);
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws AuthorizationException|ValidationException
     */
    public function addRule(Server $server, User $actor, array $data): FirewallRule
    {
        $this->assertCanManage($actor, $server);
        $clean = $this->normalizeRule($data);

        if ($server->firewallRules()->count() >= self::MAX_RULES) {
            throw ValidationException::withMessages([
                'rule' => 'Maszyna może mieć najwyżej '.self::MAX_RULES.' reguł zapory. Połącz zakresy portów albo usuń zbędne.',
            ]);
        }

        $asAdmin = $actor->isStaff() && ! empty($data['admin_rule']);

        $rule = $server->firewallRules()->create([
            ...$clean,
            'managed_by' => $asAdmin ? FirewallRule::MANAGED_BY_ADMIN : FirewallRule::MANAGED_BY_CUSTOMER,
            'enabled' => true,
            'position' => (int) FirewallRule::query()->where('server_id', $server->id)->max('position') + 1,
        ]);

        // Dodanie pierwszej reguły włącza zaporę — inaczej reguła by nie działała,
        // a klient nie wiedziałby dlaczego.
        if (! $server->firewall_enabled) {
            $server->forceFill(['firewall_enabled' => true])->save();
        }

        AuditLog::record('firewall.rule_added', $server, ['rule' => $rule->describe(), 'admin' => $asAdmin], $actor);
        $this->sync($server, $actor);

        return $rule;
    }

    /** @return list<FirewallRule> */
    public function addPreset(Server $server, User $actor, string $preset): array
    {
        abort_unless(isset(self::PRESETS[$preset]), 404);

        return DB::transaction(fn () => array_map(
            fn (array $rule) => $this->addRule($server, $actor, [
                'action' => 'accept', 'direction' => 'in', ...$rule,
            ]),
            self::PRESETS[$preset]['rules'],
        ));
    }

    public function deleteRule(Server $server, User $actor, FirewallRule $rule): void
    {
        $this->assertRuleEditable($actor, $server, $rule);

        $description = $rule->describe();
        $rule->delete();

        AuditLog::record('firewall.rule_deleted', $server, ['rule' => $description], $actor);
        $this->sync($server, $actor);
    }

    public function toggleRule(Server $server, User $actor, FirewallRule $rule): void
    {
        $this->assertRuleEditable($actor, $server, $rule);

        $rule->forceFill(['enabled' => ! $rule->enabled])->save();
        $this->sync($server, $actor);
    }

    /** Przesuwa regułę o jedno miejsce w obrębie reguł tego samego właściciela. */
    public function moveRule(Server $server, User $actor, FirewallRule $rule, string $direction): void
    {
        $this->assertRuleEditable($actor, $server, $rule);

        $siblings = $server->firewallRules()->where('managed_by', $rule->managed_by)->get()->values();
        $index = $siblings->search(fn (FirewallRule $r) => $r->id === $rule->id);
        $target = $direction === 'up' ? $index - 1 : $index + 1;

        if ($index === false || ! isset($siblings[$target])) {
            return;
        }

        $ordered = $siblings->all();
        [$ordered[$index], $ordered[$target]] = [$ordered[$target], $ordered[$index]];

        DB::transaction(function () use ($ordered) {
            foreach ($ordered as $position => $sibling) {
                $sibling->forceFill(['position' => $position + 1])->save();
            }
        });

        $this->sync($server, $actor);
    }

    /** @return array{enabled: bool, inbound: string, outbound: string} */
    public static function policyPayload(Server $server): array
    {
        return [
            'enabled' => (bool) $server->firewall_enabled,
            'inbound' => $server->firewall_inbound ?: 'accept',
            'outbound' => $server->firewall_outbound ?: 'accept',
        ];
    }

    // --- pomocnicze ---------------------------------------------------------

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeRule(array $data): array
    {
        $protocol = $data['protocol'];
        $portFrom = in_array($protocol, ['tcp', 'udp'], true) ? ($data['port_from'] ?? null) : null;
        $portTo = $portFrom !== null ? ($data['port_to'] ?? null) : null;

        if ($portTo !== null && (int) $portTo < (int) $portFrom) {
            throw ValidationException::withMessages(['port_to' => 'Koniec zakresu portów nie może być mniejszy niż początek.']);
        }

        $source = trim((string) ($data['source'] ?? ''));

        if ($source !== '') {
            try {
                $source = str_contains($source, '/')
                    ? IpMath::normalizeCidr($source)
                    : IpMath::normalize($source);
            } catch (\InvalidArgumentException) {
                throw ValidationException::withMessages([
                    'source' => 'Adres musi być adresem IP albo podsiecią CIDR, np. 198.51.100.7 albo 10.0.0.0/8.',
                ]);
            }
        }

        return [
            'action' => $data['action'],
            'direction' => $data['direction'],
            'protocol' => $protocol,
            'port_from' => $portFrom !== null ? (int) $portFrom : null,
            'port_to' => $portTo !== null && (int) $portTo !== (int) $portFrom ? (int) $portTo : null,
            'source' => $source !== '' ? $source : null,
            'comment' => $data['comment'] ?? null,
        ];
    }

    private function assertCanManage(User $actor, Server $server): void
    {
        if (! $this->canManage($actor, $server)) {
            throw new AuthorizationException('Zapora tej maszyny została zablokowana przez administratora.');
        }
    }

    private function assertRuleEditable(User $actor, Server $server, FirewallRule $rule): void
    {
        abort_unless($rule->server_id === $server->id, 404);
        $this->assertCanManage($actor, $server);

        if ($rule->isAdminRule() && ! $actor->isStaff()) {
            throw new AuthorizationException('Tę regułę ustawił administrator — nie możesz jej zmienić.');
        }
    }

    private function sync(Server $server, User $actor): void
    {
        // Maszyna bez węzła albo w trakcie tworzenia dostanie reguły razem
        // z pierwszą konfiguracją sieci.
        if ($server->hypervisor_id !== null && filled($server->agent_uuid)) {
            $this->provisioner->syncNetwork($server, $actor);
        }
    }
}
