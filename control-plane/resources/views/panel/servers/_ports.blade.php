{{-- Przekierowania portów NAT: siatka portów bloku węzła → port w maszynie, zapis jednym przyciskiem. --}}
@php
    use App\Domain\Network\PortForwarding as PF;
    $natIps = $server->ipAddresses->filter(fn ($ip) => $ip->natPorts() !== null);
    $canEdit = auth()->user()->can('firewall', $server);
@endphp

@if ($natIps->isNotEmpty())
    <div class="card" id="ports" style="margin-top:16px">
        <form method="POST" action="{{ route('panel.servers.ports.update', $server) }}" data-ports-form>
            @csrf @method('PUT')
            <div class="ports-head">
                <div>
                    <h3 class="card-title" style="margin-bottom:4px"><x-icon name="network" :size="16"/> {{ __('Porty') }}</h3>
                    @foreach ($natIps as $ip)
                        <p class="hint" style="margin:0">
                            {!! __('Wspólny adres IPv4: :address. Z zewnątrz maszyna jest osiągalna przez poniższe porty.', ['address' => '<strong class="mono">'.e($ip->natEndpoint()).'</strong>']) !!}
                        </p>
                    @endforeach
                </div>
                @if ($canEdit)
                    <button class="btn btn-primary" type="submit" data-ports-save disabled>{{ __('Zapisz') }}</button>
                @endif
            </div>
            @foreach (['ports', 'ports.*', 'ports.*.*'] as $field)
                @error($field) <div class="alert alert-error" style="margin:10px 0 0">{{ $message }}</div> @enderror
            @endforeach

            @foreach ($natIps as $ip)
                @php $host = $ip->natEndpoint(); @endphp
                <div class="ports-grid">
                    @foreach (PF::mapping($ip, $server) as $entry)
                        @php
                            $ext = $entry['external'];
                            $state = match ($entry['kind']) { PF::KIND_FIXED => 'fixed', PF::KIND_CUSTOM => 'active', default => 'direct' };
                            $endpoint = $host.':'.$ext;
                        @endphp
                        <div class="port-tile is-{{ $state }}" data-port-tile>
                            <div class="port-map">
                                <span class="mono port-ext">{{ $ext }}</span>
                                <span class="muted" aria-hidden="true">→</span>
                                @if ($state === 'fixed')
                                    <span class="port-box mono" title="{{ $entry['label'] }}">{{ $entry['internal'] }}</span>
                                @else
                                    <input class="port-box mono" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="5"
                                           name="ports[{{ $ip->id }}][{{ $ext }}]" aria-label="{{ __('Port w maszynie dla :port', ['port' => $ext]) }}"
                                           value="{{ old("ports.{$ip->id}.{$ext}", $state === 'active' ? $entry['internal'] : '') }}"
                                           placeholder="{{ $ext }}" data-port-input data-initial="{{ $state === 'active' ? $entry['internal'] : '' }}"
                                           @disabled(! $canEdit)>
                                @endif
                                <button class="icon-btn" type="button" data-copy="{{ $endpoint }}" title="{{ __('Kopiuj :endpoint', ['endpoint' => $endpoint]) }}"><x-icon name="copy" :size="14"/></button>
                            </div>
                            <div class="port-endpoint mono">{{ $endpoint }}</div>
                            <div class="port-state">
                                @if ($state === 'fixed')
                                    <span class="pill neutral">{{ $entry['label'] }}</span>
                                @elseif ($state === 'active')
                                    <span class="pill ok">{{ $entry['label'] ?: __('przekierowany') }}</span>
                                @else
                                    <span class="pill warning">{{ __('1:1') }}</span>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endforeach
            <p class="hint" style="margin-bottom:0">
                {{ __('Wpisz port w maszynie, na który ma prowadzić port zewnętrzny. Puste pole: port przechodzi na ten sam numer (1:1). Pierwsze porty są stałe i prowadzą do SSH (a w Windows także do RDP).') }}
            </p>
        </form>
    </div>

    @push('scripts')
        <script>
            (function () {
                const form = document.querySelector('[data-ports-form]');
                if (!form) return;
                const save = form.querySelector('[data-ports-save]');
                const refresh = function () {
                    let dirty = false;
                    form.querySelectorAll('[data-port-input]').forEach(function (input) {
                        input.value = input.value.replace(/\D/g, '').slice(0, 5);
                        const changed = input.value !== input.dataset.initial;
                        dirty = dirty || changed;
                        const tile = input.closest('[data-port-tile]');
                        tile.classList.toggle('is-changed', changed);
                        tile.classList.toggle('is-active', input.value !== '' && input.value !== input.placeholder);
                        tile.classList.toggle('is-direct', input.value === '' || input.value === input.placeholder);
                    });
                    if (save) save.disabled = !dirty;
                };
                form.addEventListener('input', refresh);
                form.querySelectorAll('[data-copy]').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        if (navigator.clipboard) navigator.clipboard.writeText(btn.dataset.copy);
                        btn.classList.add('is-copied');
                        setTimeout(function () { btn.classList.remove('is-copied'); }, 1200);
                    });
                });
                refresh();
            })();
        </script>
    @endpush
@endif
