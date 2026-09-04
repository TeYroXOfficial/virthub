@php
    $tabs = [
        'panel.admin.index' => 'Przegląd',
        'panel.admin.hypervisors' => 'Hypervisory',
        'panel.admin.servers' => 'Maszyny',
        'panel.admin.packages' => 'Pakiety',
        'panel.admin.templates' => 'Szablony',
        'panel.admin.ip-pools' => 'Adresy IP',
    ];
@endphp

<nav class="subnav">
    @foreach ($tabs as $route => $label)
        <a href="{{ route($route) }}" @if(request()->routeIs($route)) aria-current="page" @endif>{{ $label }}</a>
    @endforeach
</nav>

@if (session('enrollment'))
    @php $enrollment = session('enrollment'); @endphp
    <div class="card" style="border-color: var(--accent);">
        <h3>Polecenie instalacyjne dla węzła „{{ $enrollment['hypervisor'] }}"</h3>
        <p>
            Zaloguj się jako root na nowym serwerze i wklej to jedno polecenie.
            Zainstaluje KVM, agenta i wszystkie zależności, po czym węzeł sam zgłosi się do panelu.
        </p>
        <p class="secret" id="enroll-cmd">{{ $enrollment['command'] }}</p>
        <div class="btn-row">
            <button class="btn btn-primary" type="button" onclick="
                navigator.clipboard.writeText(document.getElementById('enroll-cmd').textContent.trim());
                this.textContent = 'Skopiowane';
            ">Kopiuj polecenie</button>
        </div>
        <p class="hint" style="margin-top:12px">
            Bilet jest jednorazowy i wygasa
            {{ $enrollment['expires_at']?->diffForHumans() ?? 'za godzinę' }}.
            Zobaczysz go tylko teraz — po odświeżeniu strony zniknie.
            Jeśli przepadnie, wygeneruj nowy przyciskiem przy węźle.
        </p>
    </div>
@endif
