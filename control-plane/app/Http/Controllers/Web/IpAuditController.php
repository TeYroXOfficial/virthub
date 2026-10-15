<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\IpAssignment;
use App\Support\Export;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sieć → Audyt IP: kto (maszyna, klient, węzeł) miał adres i kiedy — także
 * po usunięciu maszyny. Do zgłoszeń nadużyć i rozliczeń; eksport CSV/JSON/YAML.
 */
class IpAuditController extends Controller
{
    private const COLUMNS = ['address', 'version', 'hostname', 'server_id', 'user_email', 'user_id', 'hypervisor_name', 'assigned_at', 'released_at'];

    public function index(Request $request): View
    {
        return view('panel.admin.network.ip-audit', [
            'rows' => $this->query($request)->paginate(50)->withQueryString(),
            'filters' => $this->filters($request),
        ]);
    }

    public function export(Request $request, string $format): StreamedResponse
    {
        abort_unless(in_array($format, Export::FORMATS, true), 404);
        $rows = $this->query($request)->lazy(500)->map(fn (IpAssignment $a) => [
            ...$a->only(self::COLUMNS),
            'assigned_at' => $a->assigned_at?->toIso8601String(),
            'released_at' => $a->released_at?->toIso8601String(),
        ]);

        return Export::download($rows, self::COLUMNS, $format, 'ip-audit-'.now()->format('Ymd-His'));
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        return $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'state' => ['nullable', Rule::in(['active', 'released'])],
            'version' => ['nullable', Rule::in(['4', '6'])],
        ]);
    }

    /** @return Builder<IpAssignment> */
    private function query(Request $request): Builder
    {
        $f = $this->filters($request);

        return IpAssignment::query()
            ->when($f['q'] ?? null, fn (Builder $q, string $term) => $q->where(fn (Builder $w) => $w
                ->where('address', 'like', $term.'%')->orWhere('hostname', 'like', '%'.$term.'%')
                ->orWhere('user_email', 'like', '%'.$term.'%')))
            // Okres: przydział, który choć częściowo nachodzi na podany przedział.
            ->when($f['from'] ?? null, fn (Builder $q, string $from) => $q->where(fn (Builder $w) => $w->whereNull('released_at')->orWhere('released_at', '>=', $from)))
            ->when($f['to'] ?? null, fn (Builder $q, string $to) => $q->where('assigned_at', '<=', $to.' 23:59:59'))
            ->when(($f['state'] ?? null) === 'active', fn (Builder $q) => $q->whereNull('released_at'))
            ->when(($f['state'] ?? null) === 'released', fn (Builder $q) => $q->whereNotNull('released_at'))
            ->when($f['version'] ?? null, fn (Builder $q, string $v) => $q->where('version', (int) $v))
            ->orderByDesc('assigned_at')->orderByDesc('id');
    }
}
