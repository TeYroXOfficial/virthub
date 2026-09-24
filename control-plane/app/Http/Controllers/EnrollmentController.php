<?php

namespace App\Http\Controllers;

use App\Domain\Provisioning\HypervisorEnrollment;
use App\Domain\Updates\Updates;
use App\Models\Hypervisor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use PharData;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Rejestracja hypervisora jednym poleceniem.
 *
 * Endpointy są publiczne w sensie routingu — świeży serwer nie ma jeszcze
 * żadnych poświadczeń. Całą ochronę niesie bilet: jednorazowy, ważny godzinę,
 * przechowywany w bazie wyłącznie jako skrót.
 *
 * Kolejność jest istotna: skrypt najpierw pyta o widziany adres (`whoami`),
 * generuje pod niego certyfikat, dopiero potem melduje się (`complete`) i w
 * odpowiedzi dostaje sekrety agenta. Bilet unieważnia się na tym ostatnim kroku.
 */
class EnrollmentController extends Controller
{
    private const BAD_TOKEN = 'Bilet rejestracyjny jest nieprawidlowy, wygasl albo zostal juz '
        .'uzyty. Wygeneruj nowy w panelu administratora (Administracja -> Hypervisory).';

    public function __construct(private readonly HypervisorEnrollment $enrollment) {}

    /** Skrypt instalacyjny — to jego pobiera `curl … | bash`. */
    public function script(string $token): Response
    {
        $hypervisor = $this->enrollment->findByToken($token);

        if ($hypervisor === null) {
            return $this->problem(self::BAD_TOKEN, 404);
        }

        $script = strtr(
            file_get_contents(resource_path('stubs/install-agent.sh')),
            [
                '__PANEL_URL__' => rtrim(config('app.url'), '/'),
                '__TOKEN__' => $token,
                '__TLS_PORT__' => (string) HypervisorEnrollment::AGENT_TLS_PORT,
                '__HYPERVISOR_NAME__' => $hypervisor->name,
            ],
        );

        return response($script, 200, [
            'Content-Type' => 'text/x-shellscript; charset=utf-8',
            // Bilet siedzi w adresie — nie ma powodu, żeby ktokolwiek go cache'ował.
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /**
     * Adres, z którego panel widzi zgłaszający się węzeł.
     *
     * Serwer za NAT-em nie zna swojego publicznego IP, a certyfikat musi
     * pasować do adresu, pod który panel będzie się łączył — inaczej weryfikacja
     * TLS odrzuci połączenie przy pierwszym zadaniu.
     */
    public function whoami(Request $request, string $token): JsonResponse
    {
        $this->resolve($token);

        return response()->json(['ip' => $request->ip()]);
    }

    /** Kod agenta spakowany z katalogu wskazanego w konfiguracji. */
    public function agentBundle(string $token): BinaryFileResponse|Response
    {
        if ($this->enrollment->findByToken($token) === null) {
            return $this->problem(self::BAD_TOKEN, 404);
        }

        $source = config('virthub.agent_source_path');

        if (! is_dir($source) || ! is_file($source.'/requirements.txt')) {
            return $this->problem(
                'Panel nie ma dostepu do kodu agenta. Wgraj katalog node-agent na serwer '
                .'panelu i wskaz go zmienna VIRTHUB_AGENT_SOURCE_PATH w pliku .env, '
                ."potem wykonaj: php artisan config:cache\nSzukano w: {$source}",
                503,
            );
        }

        return response()->download($this->buildBundle($source), 'agent.tar.gz');
    }

    /** Meldunek węzła: zapisujemy dane, oddajemy sekrety, unieważniamy bilet. */
    public function complete(Request $request, string $token): JsonResponse
    {
        $hypervisor = $this->resolve($token);

        $validated = $request->validate([
            'hostname' => ['required', 'string', 'max:253'],
            'cpu_cores' => ['required', 'integer', 'min:1', 'max:1024'],
            'ram_mb' => ['required', 'integer', 'min:1024'],
            'disk_gb' => ['required', 'integer', 'min:10'],
            'tls_cert' => ['required', 'string', 'max:8192'],
            // Starsze instalatory nie wysyłały tego pola — wtedy węzeł to KVM.
            'virtualization' => ['nullable', \Illuminate\Validation\Rule::in(['kvm', 'lxc'])],
        ]);

        if (! str_contains($validated['tls_cert'], 'BEGIN CERTIFICATE')) {
            return response()->json([
                'message' => 'Przesłany certyfikat nie jest w formacie PEM.',
            ], 422);
        }

        $secrets = $this->enrollment->complete($hypervisor, $validated, $request->ip());

        Log::info('Hypervisor zarejestrowany', [
            'hypervisor_id' => $hypervisor->id,
            'source_ip' => $request->ip(),
        ]);

        return response()->json($secrets);
    }

    // --- pomocnicze ---------------------------------------------------------

    private function resolve(string $token): Hypervisor
    {
        $hypervisor = $this->enrollment->findByToken($token);

        abort_if($hypervisor === null, 404, self::BAD_TOKEN);

        return $hypervisor;
    }

    /**
     * Błąd w postaci czystego tekstu.
     *
     * Te endpointy woła `curl` ze skryptu powłoki, nie przeglądarka. Strona
     * błędu HTML wylądowałaby w terminalu jako ściana znaczników, w której nie
     * widać, co właściwie poszło nie tak.
     */
    private function problem(string $message, int $status): Response
    {
        return response("VirtHub: {$message}\n", $status, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /**
     * Buduje archiwum z kodem agenta. Wynik jest cache'owany na dysku i
     * odświeżany, gdy źródło się zmieni — instalacja dziesięciu węzłów nie
     * pakuje tego samego katalogu dziesięć razy.
     */
    private function buildBundle(string $source): string
    {
        $stamp = $this->sourceFingerprint($source);
        $target = storage_path("app/agent-bundle-{$stamp}.tar");
        $archive = $target.'.gz';

        if (is_file($archive)) {
            return $archive;
        }

        // Stare wersje pakietu tylko zajmują miejsce.
        foreach (glob(storage_path('app/agent-bundle-*.tar*')) ?: [] as $old) {
            @unlink($old);
        }

        $phar = new PharData($target);
        $phar->buildFromDirectory($source, '/^(?!.*(\.venv|__pycache__|\.pytest_cache|\.env$|VERSION$)).*$/');

        // Nowy węzeł dostaje kod z tej samej wersji co panel — zapisujemy ją,
        // żeby Administracja → Aktualizacje wiedziała, co na nim działa.
        if (($version = app(Updates::class)->panelVersion()) !== null) {
            $phar->addFromString('VERSION', $version."\n");
        }
        $phar->compress(\Phar::GZ);
        @unlink($target);

        return $archive;
    }

    private function sourceFingerprint(string $source): string
    {
        $newest = 0;

        foreach (['requirements.txt', 'agent/main.py', 'agent/driver.py'] as $file) {
            $path = $source.'/'.$file;
            if (is_file($path)) {
                $newest = max($newest, filemtime($path));
            }
        }

        return substr(hash('sha256', $source.$newest.app(Updates::class)->panelVersion()), 0, 12);
    }
}
