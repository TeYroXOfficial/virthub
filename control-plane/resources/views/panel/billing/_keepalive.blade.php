{{--
    Potwierdzanie aktywności usługi: przycisk „Przedłuż” i pasek postępu.
    Faza 1 — pasek dochodzi do chwili, gdy przycisk się odblokuje.
    Faza 2 — pasek maleje do wygaśnięcia; trzeba kliknąć przed końcem.
--}}
@if ($service->needsKeepalive() && ($service->status === 'active' || ($service->status === 'suspended' && $service->suspend_reason === 'inactive')) && $service->keepalive_until)
    @php
        $until = $service->keepalive_until;
        $unlock = $service->keepaliveUnlocksAt() ?? $until;
        $last = \App\Domain\Billing\Cycle::sub($until, $service->keepalive_interval);
        $suspended = $service->status === 'suspended';
        $deleteAt = $service->inactiveDeleteAt();
    @endphp
    <div class="card keepalive {{ $suspended ? 'is-suspended' : '' }}" style="margin-bottom:16px"
         data-keepalive data-last="{{ $last->getTimestampMs() }}" data-unlock="{{ $unlock->getTimestampMs() }}" data-until="{{ $until->getTimestampMs() }}" data-suspended="{{ $suspended ? 1 : 0 }}" data-suspended-at="{{ $service->suspended_at?->getTimestampMs() }}" data-delete="{{ $deleteAt?->getTimestampMs() }}">
        <div class="keepalive-head">
            <div>
                <h3 class="card-title" style="margin:0"><x-icon name="clock" :size="16"/> {{ __('Potwierdzanie aktywności') }}</h3>
                <p class="hint" data-keepalive-text style="margin:4px 0 0">
                    @if ($suspended)
                        {{ __('Usługa jest zawieszona, bo nie potwierdzono aktywności. Kliknij „Przedłuż”, a wróci od razu.') }}
                        @if ($deleteAt) <strong>{{ __('Bez tego :date zostanie usunięta z serwera razem z danymi.', ['date' => $deleteAt->format('d.m.Y H:i')]) }}</strong> @endif
                    @elseif ($unlock->isFuture())
                        {{ __('Następne przedłużenie możliwe :date.', ['date' => $unlock->format('d.m.Y H:i')]) }}
                    @else
                        {{ __('Przedłuż przed :date, inaczej usługa zostanie zawieszona.', ['date' => $until->format('d.m.Y H:i')]) }}
                    @endif
                </p>
            </div>
            <form method="POST" action="{{ route('panel.billing.service.keepalive', $service) }}" style="margin:0">
                @csrf
                <button class="btn btn-primary" type="submit" data-keepalive-button @disabled(! $service->canKeepalive())>
                    <x-icon name="refresh" :size="15"/> {{ __('Przedłuż') }}
                </button>
            </form>
        </div>
        <div class="keepalive-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100"><i data-keepalive-fill></i></div>
        <div class="keepalive-meta">
            <span data-keepalive-left></span>
            <span class="muted">{{ __('Każde kliknięcie przedłuża ważność o :period.', ['period' => \App\Domain\Billing\Cycle::duration($service->keepalive_interval)]) }}</span>
        </div>
        @error('keepalive') <p class="hint" style="color:var(--critical)">{{ $message }}</p> @enderror
    </div>
    @once
        @push('scripts')
            <script>
                (function () {
                    var T = {
                        unlockIn: @js(__('Przycisk odblokuje się za :time')),
                        expiresIn: @js(__('Zostało :time do zawieszenia')),
                        expired: @js(__('Czas minął — usługa zostanie zaraz zawieszona')),
                        suspended: @js(__('Usługa zawieszona')),
                        deleteIn: @js(__('Usunięcie z serwera za :time')),
                        d: @js(__(':n d')), h: @js(__(':n godz.')), m: @js(__(':n min')), s: @js(__(':n s')),
                    };
                    function fmt(ms) {
                        var s = Math.max(0, Math.floor(ms / 1000));
                        var d = Math.floor(s / 86400), h = Math.floor(s % 86400 / 3600), m = Math.floor(s % 3600 / 60), sec = s % 60;
                        var out = [];
                        if (d) out.push(T.d.replace(':n', d));
                        if (h || d) out.push(T.h.replace(':n', h));
                        out.push(T.m.replace(':n', m));
                        if (!d && !h) out.push(T.s.replace(':n', sec));
                        return out.join(' ');
                    }
                    document.querySelectorAll('[data-keepalive]').forEach(function (box) {
                        var last = +box.dataset.last, unlock = +box.dataset.unlock, until = +box.dataset.until;
                        var fill = box.querySelector('[data-keepalive-fill]'), left = box.querySelector('[data-keepalive-left]');
                        var btn = box.querySelector('[data-keepalive-button]'), bar = box.querySelector('[role=progressbar]');
                        function tick() {
                            var now = Date.now(), pct, text;
                            if (box.dataset.suspended === '1') {
                                // Zawieszona: pasek maleje do usunięcia z serwera.
                                var del = +box.dataset.delete, from = +box.dataset.suspendedAt;
                                pct = del && del > from ? (del - now) / (del - from) * 100 : 0;
                                text = del ? T.deleteIn.replace(':time', fmt(del - now)) : T.suspended;
                                btn.disabled = false;
                            } else if (now < unlock) {
                                pct = unlock > last ? (now - last) / (unlock - last) * 100 : 100;
                                text = T.unlockIn.replace(':time', fmt(unlock - now));
                                box.classList.remove('is-open');
                            } else {
                                pct = until > unlock ? (until - now) / (until - unlock) * 100 : 0;
                                text = now < until ? T.expiresIn.replace(':time', fmt(until - now)) : T.expired;
                                box.classList.add('is-open');
                                btn.disabled = false;
                            }
                            pct = Math.max(0, Math.min(100, pct));
                            fill.style.width = pct.toFixed(2) + '%';
                            bar.setAttribute('aria-valuenow', Math.round(pct));
                            left.textContent = text;
                        }
                        tick();
                        setInterval(tick, 1000);
                    });
                })();
            </script>
        @endpush
    @endonce
@endif
