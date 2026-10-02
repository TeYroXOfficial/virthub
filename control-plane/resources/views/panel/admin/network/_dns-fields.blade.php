{{-- Resolvery DNS bloku: gotowy zestaw (osobno dla IPv4 i IPv6) albo własna lista. --}}
@php
    $presets = \App\Domain\Network\IpPoolManager::DNS_PRESETS;
    $presetValue = old('dns_preset', $preset ?? 'cloudflare');
@endphp
<div class="field">
    <label for="{{ $idp }}-dns">{{ __('Resolwery DNS') }}</label>
    <select id="{{ $idp }}-dns" name="dns_preset" data-dns-select>
        @foreach ($presets as $key => [$label, $v4, $v6])
            <option value="{{ $key }}" @selected($presetValue === $key)
                    data-v4="{{ implode(', ', $v4) }}" data-v6="{{ implode(', ', $v6) }}">{{ $label }} ({{ $v4[0] }})</option>
        @endforeach
        <option value="default" @selected($presetValue === 'default')>{{ __('Domyślne panelu (Cloudflare + Quad9)') }}</option>
        <option value="custom" @selected($presetValue === 'custom')>{{ __('Własne') }}</option>
    </select>
    <div class="hint">{{ __('Te serwery maszyny dostają w konfiguracji sieci. Zestaw dobieramy do wersji IP bloku.') }}</div>
</div>
<div class="field" data-dns-custom>
    <label for="{{ $idp }}-ns">{{ __('Własne resolwery') }} <span class="muted">{{ __('(po przecinku, najwyżej 4)') }}</span></label>
    <input id="{{ $idp }}-ns" name="nameservers" type="text" value="{{ old('nameservers', $nameservers ?? '') }}" placeholder="1.1.1.1, 8.8.8.8">
    @error('nameservers') <div class="hint" style="color:var(--critical)">{{ $message }}</div> @enderror
</div>
