<?php

namespace App\Enums;

/**
 * Stan maszyny widziany przez panel.
 *
 * Rozdzielamy stany trwałe (running, stopped) od przejściowych (building,
 * rebuilding, resizing, deleting). W stanie przejściowym trwa zadanie na
 * hypervisorze i kolejna akcja klienta musi zostać odrzucona — inaczej dwa
 * zadania nadpisałyby sobie dysk tej samej maszyny.
 */
enum ServerState: string
{
    case Building = 'building';
    case Running = 'running';
    case Stopped = 'stopped';
    case Suspended = 'suspended';
    case Rebuilding = 'rebuilding';
    case Resizing = 'resizing';
    case Deleting = 'deleting';
    case Error = 'error';

    public function isTransitioning(): bool
    {
        return in_array($this, [
            self::Building,
            self::Rebuilding,
            self::Resizing,
            self::Deleting,
        ], true);
    }

    /** Czy klient może w tym stanie zlecić kolejną operację. */
    public function acceptsCommands(): bool
    {
        return ! $this->isTransitioning() && $this !== self::Suspended;
    }

    public function label(): string
    {
        return match ($this) {
            self::Building => __('Tworzenie'),
            self::Running => __('Działa'),
            self::Stopped => __('Zatrzymany'),
            self::Suspended => __('Zawieszony'),
            self::Rebuilding => __('Przebudowa'),
            self::Resizing => __('Zmiana pakietu'),
            self::Deleting => __('Usuwanie'),
            self::Error => __('Błąd'),
        };
    }

    /** Kolor semantyczny dla interfejsu: ok / warning / critical / neutral. */
    public function tone(): string
    {
        return match ($this) {
            self::Running => 'ok',
            self::Building, self::Rebuilding, self::Resizing, self::Deleting => 'warning',
            self::Error, self::Suspended => 'critical',
            self::Stopped => 'neutral',
        };
    }

    /** Mapowanie stanu zgłoszonego przez libvirt na stan panelu. */
    public static function fromAgentState(string $agentState): self
    {
        return match ($agentState) {
            'running', 'blocked' => self::Running,
            'stopped', 'nostate', 'shutting-down' => self::Stopped,
            'paused', 'suspended' => self::Suspended,
            'crashed' => self::Error,
            default => self::Error,
        };
    }
}
