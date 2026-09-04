<?php

namespace App\Domain\Provisioning;

use App\Models\AuditLog;
use App\Models\Hypervisor;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Rejestracja nowego hypervisora jednym poleceniem.
 *
 * Administrator dodaje węzeł w panelu i dostaje polecenie do wklejenia na
 * świeżym serwerze. Skrypt instaluje KVM, agenta i wszystkie zależności, po
 * czym sam melduje się z powrotem — panel dowiaduje się z tego zgłoszenia,
 * jaki węzeł ma adres, ile ma zasobów i jakim certyfikatem się przedstawia.
 *
 * Bilet rejestracyjny jest jednorazowy i krótko żyjący, bo skrypt zwraca w
 * odpowiedzi sekrety agenta. W bazie leży wyłącznie jego skrót — wyciek dumpu
 * nie daje nikomu gotowego linku instalacyjnego.
 */
class HypervisorEnrollment
{
    public const TOKEN_TTL_MINUTES = 60;

    /** Port, na którym nasłuchuje nginx z certyfikatem węzła. */
    public const AGENT_TLS_PORT = 8443;

    /**
     * Wystawia bilet rejestracyjny. Zwraca token w postaci jawnej — pokazywany
     * dokładnie raz, przy tworzeniu węzła.
     */
    public function issueToken(Hypervisor $hypervisor): string
    {
        $token = Str::random(48);

        $hypervisor->forceFill([
            'enrollment_token_hash' => hash('sha256', $token),
            'enrollment_expires_at' => now()->addMinutes(self::TOKEN_TTL_MINUTES),
        ])->save();

        AuditLog::record('hypervisor.enrollment_issued', $hypervisor, [
            'expires_at' => $hypervisor->enrollment_expires_at->toIso8601String(),
        ]);

        return $token;
    }

    public function findByToken(string $token): ?Hypervisor
    {
        $hypervisor = Hypervisor::query()
            ->where('enrollment_token_hash', hash('sha256', $token))
            ->first();

        if ($hypervisor === null) {
            return null;
        }

        if ($hypervisor->enrollment_expires_at === null
            || $hypervisor->enrollment_expires_at->isPast()) {
            return null;
        }

        return $hypervisor;
    }

    /**
     * Domyka rejestrację: zapisuje zgłoszone przez węzeł dane, generuje sekrety
     * i unieważnia bilet.
     *
     * @param  array{hostname: string, cpu_cores: int, ram_mb: int, disk_gb: int, tls_cert: string}  $report
     * @param  string  $sourceIp  adres, z którego przyszło zgłoszenie — bierzemy
     *                            go zamiast adresu podanego przez skrypt, bo
     *                            serwer za NAT-em nie zna swojego publicznego IP
     * @return array{agent_token: string, callback_secret: string}
     */
    public function complete(Hypervisor $hypervisor, array $report, string $sourceIp): array
    {
        $agentToken = Str::random(64);
        $callbackSecret = Str::random(64);

        $hypervisor->forceFill([
            'hostname' => $report['hostname'],
            'agent_url' => sprintf('https://%s:%d', $sourceIp, self::AGENT_TLS_PORT),
            'agent_token' => $agentToken,
            'callback_secret' => $callbackSecret,
            'agent_tls_cert' => $report['tls_cert'],

            // Pojemność zgłoszona przez węzeł, pomniejszona o zapas na system
            // hosta. Bez zapasu hypervisor zaczyna się dławić przy pełnym
            // obłożeniu i cierpią na tym maszyny wszystkich klientów.
            'cpu_cores_total' => $this->reserveForHost($report['cpu_cores'], 2),
            'ram_mb_total' => $this->reserveForHost($report['ram_mb'], 4096),
            'disk_gb_total' => $this->reserveForHost($report['disk_gb'], 20),

            'status' => Hypervisor::STATUS_OFFLINE, // online dopiero po heartbeacie
            'enrolled_at' => now(),
            'enrollment_token_hash' => null,
            'enrollment_expires_at' => null,
        ])->save();

        AuditLog::record('hypervisor.enrolled', $hypervisor, [
            'source_ip' => $sourceIp,
            'hostname' => $report['hostname'],
            'cpu_cores' => $hypervisor->cpu_cores_total,
            'ram_mb' => $hypervisor->ram_mb_total,
        ]);

        return ['agent_token' => $agentToken, 'callback_secret' => $callbackSecret];
    }

    /**
     * Zapisuje certyfikat węzła na dysku i zwraca ścieżkę dla klienta HTTP.
     *
     * Guzzle potrafi weryfikować połączenie wobec pliku PEM, ale nie wobec
     * łańcucha trzymanego w pamięci — stąd zapis do storage. Plik jest
     * odtwarzany z bazy, więc jego skasowanie niczego nie psuje.
     */
    public function certificatePath(Hypervisor $hypervisor): ?string
    {
        if (blank($hypervisor->agent_tls_cert)) {
            return null;
        }

        $relative = "hypervisor-certs/{$hypervisor->id}.pem";
        $disk = Storage::disk('local');

        if (! $disk->exists($relative)
            || $disk->get($relative) !== $hypervisor->agent_tls_cert) {
            $disk->put($relative, $hypervisor->agent_tls_cert);
        }

        return $disk->path($relative);
    }

    private function reserveForHost(int $reported, int $reserve): int
    {
        // Nigdy poniżej zera i nigdy zera — węzeł bez zadeklarowanej pojemności
        // wypadłby z doboru maszyn i wyglądałby na zepsuty.
        return max(1, $reported - $reserve);
    }
}
