{{-- Nawigacja administracji jest w pasku bocznym; tu zostaje tylko polecenie instalacyjne węzła. --}}
@if (session('enrollment'))
    @php $enrollment = session('enrollment'); @endphp
    <div class="card" style="border-color: var(--accent);">
        <h3>{{ __('Polecenie instalacyjne dla węzła „:hypervisor"', ['hypervisor' => $enrollment['hypervisor']]) }}</h3>
        <p>
            {{ __('Zaloguj się jako root na nowym serwerze i wklej to jedno polecenie. Zainstaluje KVM, agenta i wszystkie zależności, po czym węzeł sam zgłosi się do panelu.') }}
        </p>
        <p class="secret" id="enroll-cmd">{{ $enrollment['command'] }}</p>
        <div class="btn-row">
            <button class="btn btn-primary" type="button" onclick="
                navigator.clipboard.writeText(document.getElementById('enroll-cmd').textContent.trim());
                this.textContent = 'Skopiowane';
            ">{{ __('Kopiuj polecenie') }}</button>
        </div>
        <p class="hint" style="margin-top:12px">
            {{ __('Bilet jest jednorazowy i wygasa :godzin. Zobaczysz go tylko teraz — po odświeżeniu strony zniknie. Jeśli przepadnie, wygeneruj nowy przyciskiem przy węźle.', ['godzin' => $enrollment['expires_at']?->diffForHumans() ?? __('za godzinę')]) }}
        </p>
    </div>
@endif
