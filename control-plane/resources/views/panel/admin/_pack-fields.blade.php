@php $p = $pack; @endphp
<div class="grid grid-4">
    @unless ($p)
        <div class="field"><label for="rp-email">{{ __('E-mail klienta') }}</label><input id="rp-email" name="email" type="email" required value="{{ old('email') }}"></div>
    @endunless
    <div class="field"><label>{{ __('Nazwa') }}</label><input name="name" required maxlength="100" value="{{ $p?->name ?? old('name') }}" placeholder="{{ __('np. Cloud 16') }}"></div>
    <div class="field"><label>vCPU</label><input name="vcpu" type="number" min="1" required value="{{ $p?->vcpu ?? old('vcpu', 8) }}"></div>
    <div class="field"><label>{{ __('RAM (MB)') }}</label><input name="ram_mb" type="number" min="512" required value="{{ $p?->ram_mb ?? old('ram_mb', 16384) }}"></div>
    <div class="field"><label>{{ __('Dysk (GB)') }}</label><input name="disk_gb" type="number" min="1" required value="{{ $p?->disk_gb ?? old('disk_gb', 400) }}"></div>
    <div class="field"><label>{{ __('Maks. maszyn') }}</label><input name="max_servers" type="number" min="1" required value="{{ $p?->max_servers ?? old('max_servers', 5) }}"></div>
    <div class="field"><label>{{ __('Sieć i transfer z pakietu') }}</label><select name="vps_package_id">
        @foreach ($packages as $package) <option value="{{ $package->id }}" @selected(($p?->vps_package_id ?? (int) old('vps_package_id')) === $package->id)>{{ $package->name }}</option> @endforeach
    </select></div>
    <div class="field"><label>{{ __('Grosze / vCPU / h') }}</label><input name="price_vcpu_hour" type="number" min="0" value="{{ $p?->price_vcpu_hour }}"></div>
    <div class="field"><label>{{ __('Grosze / GB RAM / h') }}</label><input name="price_ram_gb_hour" type="number" min="0" value="{{ $p?->price_ram_gb_hour }}"></div>
    <div class="field"><label>{{ __('Grosze / GB dysku / h') }}</label><input name="price_disk_gb_hour" type="number" min="0" value="{{ $p?->price_disk_gb_hour }}"></div>
</div>
<label class="check-line" style="margin-bottom:10px"><input type="checkbox" name="is_active" value="1" @checked($p?->is_active ?? true)> {{ __('Aktywny') }}</label>
