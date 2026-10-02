@extends('layouts.panel')

@section('title', __('Bezpieczeństwo węzłów'))

@php
    $tone = ['ok' => 'ok', 'warning' => 'warning', 'critical' => 'critical'];
    $overallLabel = ['ok' => __('chroniony'), 'warning' => __('wymaga uwagi'), 'critical' => __('podatny')];
    $issueTone = ['fixed' => 'ok', 'mitigated' => 'ok', 'not_affected' => 'neutral', 'partial' => 'warning', 'vulnerable' => 'critical', 'unknown' => 'neutral'];
    $issueLabel = [
        'fixed' => __('załatane'), 'mitigated' => __('zablokowane'), 'not_affected' => __('nie dotyczy'),
        'partial' => __('częściowo'), 'vulnerable' => __('podatny'), 'unknown' => __('nieznany'),
    ];
    $canUpdate = auth()->user()->hasPermission('admin.updates');
@endphp

@section('content')
    <div class="page-header">
        <div>
            <h1>{{ __('Bezpieczeństwo węzłów') }}</h1>
            <p class="lede">{{ __('Ochrona hostów przed ucieczkami z maszyn klientów (Januscape, Zapscape, ITScape i podobne), stan jądra i podatności procesora.') }}</p>
        </div>
    </div>

    <div class="grid grid-4" style="margin-bottom:16px">
        @foreach ([['critical', __('Podatne'), 'critical'], ['warning', __('Wymagają uwagi'), 'warning'], ['ok', __('Chronione'), 'ok'], ['unknown', __('Bez raportu'), 'neutral']] as [$key, $label, $dot])
            <div class="stat"><div class="stat-label"><i class="dot {{ $dot }}"></i> {{ $label }}</div><div class="stat-value">{{ $summary[$key] }}</div></div>
        @endforeach
    </div>

    <div class="card">
        <h3 class="card-title"><x-icon name="shield" :size="16"/> {{ __('Co robi panel') }}</h3>
        <ul class="plain-list">
            <li>{{ __('Wyłącza zagnieżdżoną wirtualizację na hoście (kvm_intel / kvm_amd nested=0) — to główna droga ataku Januscape i Zapscape.') }}</li>
            <li>{{ __('Maszyny KVM nie widzą wirtualizacji sprzętowej (vmx/svm wyłączone w procesorze gościa), nawet gdyby ktoś włączył ją na hoście.') }}</li>
            <li>{{ __('Pilnuje EPT/NPT — bez nich KVM używa podatnego shadow MMU dla każdej maszyny.') }}</li>
            <li>{{ __('Instaluje najnowsze jądro z repozytorium i włącza automatyczne poprawki bezpieczeństwa (bez automatycznego restartu).') }}</li>
            <li>{{ __('Kontenery LXC i aplikacje nie mają dostępu do /dev/kvm, więc same nie są drogą do tych ataków.') }}</li>
        </ul>
        <p class="hint" style="margin:8px 0 0">{{ __('Zabezpieczenia stosuje aktualizacja węzła. Poprawka w jądrze zaczyna działać dopiero po restarcie węzła — zrób go w oknie serwisowym, bo zatrzymuje maszyny klientów.') }}</p>
    </div>

    @forelse ($nodes as $node)
        @php $r = $node->securityReport(); @endphp
        <div class="card" id="node-{{ $node->id }}" style="margin-top:16px">
            <div class="dash-head" style="padding:0 0 10px">
                <h3 class="card-title" style="margin:0">
                    <x-icon name="node" :size="16"/>
                    <a href="{{ route('panel.admin.hypervisors.show', $node) }}">{{ $node->name }}</a>
                    @if ($r)
                        <span class="pill {{ $tone[$r['overall']] ?? 'neutral' }}">{{ $overallLabel[$r['overall']] ?? $r['overall'] }}</span>
                    @else
                        <span class="pill neutral">{{ __('brak raportu') }}</span>
                    @endif
                </h3>
                <div class="btn-row">
                    <form method="POST" action="{{ route('panel.admin.security.check', $node) }}" style="margin:0">
                        @csrf
                        <button class="btn btn-sm" type="submit"><x-icon name="refresh" :size="14"/> {{ __('Sprawdź teraz') }}</button>
                    </form>
                    @if ($canUpdate)
                        <form method="POST" action="{{ route('panel.admin.updates.node', $node) }}" style="margin:0"
                              data-confirm="{{ __('Zaktualizować węzeł :name? Aktualizacja zastosuje zabezpieczenia i zainstaluje nowe jądro; maszyny działają dalej.', ['name' => $node->name]) }}">
                            @csrf
                            <button class="btn btn-sm btn-primary" type="submit"><x-icon name="shield" :size="14"/> {{ __('Zastosuj zabezpieczenia') }}</button>
                        </form>
                    @endif
                </div>
            </div>

            @if (! $r)
                <p class="muted">{{ __('Agent na tym węźle nie raportuje jeszcze stanu bezpieczeństwa — zaktualizuj węzeł.') }}</p>
            @else
                <dl class="kv" style="margin-bottom:14px">
                    <dt>{{ __('System') }}</dt><dd>{{ $r['os'] ?? '—' }} · {{ $r['arch'] }} · {{ strtoupper($r['cpu_vendor'] ?? '?') }}</dd>
                    <dt>{{ __('Jądro') }}</dt>
                    <dd class="mono">{{ $r['kernel']['running_kernel'] ?? '—' }}
                        @if ($r['kernel']['required'] ?? false)
                            <span class="pill warning">{{ __('restart → :kernel', ['kernel' => $r['kernel']['newest_kernel'] ?? '?']) }}</span>
                        @endif
                    </dd>
                    @if ($r['kvm_host'])
                        <dt>{{ __('Zagnieżdżona wirtualizacja') }}</dt>
                        <dd>@if ($r['kvm']['nested'] === null) — @else <span class="pill {{ $r['kvm']['nested'] ? 'critical' : 'ok' }}">{{ $r['kvm']['nested'] ? __('włączona') : __('wyłączona') }}</span> @endif</dd>
                        <dt>{{ __('EPT / NPT') }}</dt>
                        <dd>@if ($r['kvm']['tdp'] === null) — @else <span class="pill {{ $r['kvm']['tdp'] ? 'ok' : 'critical' }}">{{ $r['kvm']['tdp'] ? __('włączone') : __('wyłączone') }}</span> @endif</dd>
                    @endif
                    <dt>{{ __('Automatyczne poprawki') }}</dt>
                    <dd>@if ($r['auto_updates'] === null) — @else <span class="pill {{ $r['auto_updates'] ? 'ok' : 'warning' }}">{{ $r['auto_updates'] ? __('włączone') : __('wyłączone') }}</span> @endif</dd>
                    <dt>{{ __('Sprawdzono') }}</dt><dd class="muted">{{ \Illuminate\Support\Carbon::createFromTimestamp($r['checked_at'])->diffForHumans() }}</dd>
                </dl>

                <div class="table-wrap">
                    <table>
                        <thead><tr><th>{{ __('Luka') }}</th><th>{{ __('Stan') }}</th><th>{{ __('Szczegóły') }}</th></tr></thead>
                        <tbody>
                        @foreach ($r['issues'] as $issue)
                            <tr>
                                <td class="nowrap"><strong>{{ $issue['name'] }}</strong><div class="hint mono">{{ $issue['id'] }}</div></td>
                                <td><span class="pill {{ $issueTone[$issue['status']] ?? 'neutral' }}">{{ $issueLabel[$issue['status']] ?? $issue['status'] }}</span></td>
                                <td>{{ __($issue['detail']) }}<div class="hint">{{ __($issue['summary']) }}</div></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>

                @if (! empty($r['findings']))
                    <ul class="finding-list">
                        @foreach ($r['findings'] as $f)
                            <li><span class="pill {{ $f['severity'] === 'critical' ? 'critical' : 'warning' }}">{{ __($f['title']) }}</span> <span class="muted">{{ __($f['detail']) }}</span></li>
                        @endforeach
                    </ul>
                @endif

                @if (! empty($r['cpu_vulnerabilities']))
                    <details class="form-block" style="margin-top:12px">
                        <summary>{{ __('Podatności procesora (:count)', ['count' => count($r['cpu_vulnerabilities'])]) }}</summary>
                        <dl class="kv" style="margin-top:10px; font-size:13px">
                            @foreach ($r['cpu_vulnerabilities'] as $name => $state)
                                <dt class="mono">{{ $name }}</dt>
                                <dd class="{{ str_starts_with($state, 'Vulnerable') ? '' : 'muted' }}" @if (str_starts_with($state, 'Vulnerable')) style="color:var(--critical)" @endif>{{ $state }}</dd>
                            @endforeach
                        </dl>
                    </details>
                @endif
            @endif
        </div>
    @empty
        <div class="card empty" style="margin-top:16px">{{ __('Brak zainstalowanych węzłów.') }}</div>
    @endforelse
@endsection
