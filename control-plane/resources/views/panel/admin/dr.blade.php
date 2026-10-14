@extends('layouts.panel')

@section('title', __('Disaster recovery — :name', ['name' => $node->name]))

@section('content')
    <div class="page-header"><div>
        <p class="muted"><a href="{{ route('panel.admin.hypervisors.show', $node) }}">← {{ $node->name }}</a></p>
        <h1>{{ __('Disaster recovery') }}</h1>
        <p class="lede">{{ __('Gdy węzeł ulegnie awarii, jego maszyny KVM wstają na zastępczym hoście z najnowszych kopii na celach zewnętrznych — z tymi samymi adresami IP, zasobami i właścicielami. Zrzut konfiguracji (XML domen, sieć, magazyny) trafia okresowo na cel kopii.') }}</p>
    </div></div>

    @php $covered = $inventory->filter(fn ($i) => $i['backup'] !== null)->count(); @endphp
    <div class="grid grid-3">
        <section class="card"><div class="muted">{{ __('Maszyny z kopią') }}</div><div style="font-size:24px; font-weight:700">{{ $covered }} / {{ $inventory->count() }}</div></section>
        <section class="card"><div class="muted">{{ __('Ostatni zrzut konfiguracji') }}</div>
            <div style="font-size:18px; font-weight:600">{{ $node->dr_last_snapshot_at?->diffForHumans() ?? __('nigdy') }}</div>
            @if ($node->dr_snapshot_job_id) <span class="pill neutral">{{ __('w toku') }}</span> @endif
            @if ($node->dr_last_error) <div class="hint" style="color:var(--critical)">{{ $node->dr_last_error }}</div> @endif
            @if ($node->dr_last_snapshot_key) <div class="hint"><code>{{ $node->dr_last_snapshot_key }}</code> ({{ $node->drTarget?->name }})</div> @endif</section>
        <section class="card"><div class="muted">{{ __('Stan węzła') }}</div><div style="font-size:18px; font-weight:600">{{ $node->status }}</div>
            <div class="hint">{{ __('ostatni kontakt: :ago', ['ago' => $node->last_seen_at?->diffForHumans() ?? '—']) }}</div></section>
    </div>

    <section class="card">
        <h2 class="card-title"><x-icon name="archive" :size="16"/> {{ __('Polityka') }}</h2>
        <form method="POST" action="{{ route('panel.admin.dr.update', $node) }}">
            @csrf @method('PUT')
            <div class="grid grid-3">
                <div class="field"><label for="dr-plan">{{ __('Plan kopii dla maszyn bez własnego planu') }}</label>
                    <select id="dr-plan" name="dr_backup_plan_id"><option value="">{{ __('— brak —') }}</option>
                        @foreach ($plans as $plan) <option value="{{ $plan->id }}" @selected($node->dr_backup_plan_id === $plan->id)>{{ $plan->name }}</option> @endforeach</select>
                    <div class="hint">{{ __('Każda maszyna KVM tego węzła bez planu dostaje kopie według niego.') }}</div></div>
                <div class="field"><label for="dr-target">{{ __('Cel zrzutów konfiguracji') }}</label>
                    <select id="dr-target" name="dr_target_id"><option value="">{{ __('— bez zrzutów —') }}</option>
                        @foreach ($targets as $target) <option value="{{ $target->id }}" @selected($node->dr_target_id === $target->id)>{{ $target->name }} ({{ $target->type }})</option> @endforeach</select></div>
                <div class="field"><label for="dr-hours">{{ __('Zrzut co (godziny)') }}</label>
                    <input id="dr-hours" name="dr_snapshot_hours" type="number" min="1" max="720" value="{{ $node->dr_snapshot_hours }}"></div>
            </div>
            <div class="btn-row"><button class="btn btn-primary" type="submit">{{ __('Zapisz') }}</button></div>
        </form>
        @if ($node->dr_target_id)
            <form method="POST" action="{{ route('panel.admin.dr.snapshot', $node) }}" style="margin-top:8px">@csrf
                <button class="btn btn-sm" type="submit" @disabled($node->dr_snapshot_job_id)><x-icon name="refresh" :size="14"/> {{ __('Zrzut teraz') }}</button></form>
        @endif
    </section>

    <div class="card flush dash-section">
        <div class="dash-head"><h3 class="card-title" style="margin:0">{{ __('Maszyny i ich najnowsze kopie') }}</h3></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>{{ __('Maszyna') }}</th><th>{{ __('Stan') }}</th><th>{{ __('Najnowsza kopia') }}</th><th>{{ __('Cel') }}</th></tr></thead>
                <tbody>
                @forelse ($inventory as ['server' => $server, 'backup' => $backup])
                    <tr>
                        <td><a href="{{ route('panel.servers.show', $server) }}">{{ $server->hostname }}</a> @if ($server->isContainer()) <span class="pill neutral plain">LXC</span> @endif</td>
                        <td><span class="pill {{ $server->state->tone() }}">{{ $server->state->label() }}</span></td>
                        <td>@if ($backup) {{ $backup->created_at->format('Y-m-d H:i') }} <span class="muted">({{ $backup->created_at->diffForHumans() }})</span>
                            @else <span class="pill critical">{{ __('brak kopii') }}</span> @endif</td>
                        <td>{{ $backup?->target?->name ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="muted">{{ __('Na węźle nie ma maszyn.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <section class="card" style="border-color:var(--critical)">
        <h2 class="card-title"><x-icon name="shield" :size="16"/> {{ __('Odtwórz na zastępczym węźle') }}</h2>
        <p>{{ __('Używaj tylko, gdy węzeł nie wróci w rozsądnym czasie. Panel przeniesie pule IP węzła na zastępczy host, zarezerwuje zasoby i odtworzy każdą maszynę z jej najnowszej kopii (zmiany po kopii przepadną). Węzeł zostanie przełączony w tryb serwisowy. Kontenery, maszyny bez kopii i dyski w Cephie zostaną oznaczone do ręcznej obsługi.') }}</p>
        @if ($replacements->isEmpty())
            <p class="muted">{{ __('Brak dostępnego zastępczego węzła KVM (online, przyjmuje maszyny).') }}</p>
        @else
            <form method="POST" action="{{ route('panel.admin.dr.recover', $node) }}" data-confirm="{{ __('Odtworzyć maszyny węzła :name na wybranym hoście?', ['name' => $node->name]) }}" data-confirm-danger>
                @csrf
                <div class="grid grid-2">
                    <div class="field"><label for="dr-replacement">{{ __('Zastępczy węzeł') }}</label>
                        <select id="dr-replacement" name="replacement_id" required>@foreach ($replacements as $r) <option value="{{ $r->id }}">{{ $r->name }} — {{ __(':cpu vCPU, :ram GB RAM wolne', ['cpu' => $r->cpu_cores_total - $r->cpu_cores_used, 'ram' => intdiv($r->ram_mb_total - $r->ram_mb_used, 1024)]) }}</option> @endforeach</select></div>
                </div>
                <label class="check-line"><input type="checkbox" name="confirm" value="1" required> {{ __('Rozumiem, że maszyny wrócą do stanu z ostatniej kopii') }}</label>
                <div class="btn-row"><button class="btn btn-danger" type="submit">{{ __('Odtwórz maszyny') }}</button></div>
            </form>
        @endif
    </section>
@endsection
