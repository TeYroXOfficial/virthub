@php $t = $target; $c = $t ? (array) $t->config : []; $type = $t?->type ?? $type; @endphp
<div class="field"><label>{{ __('Nazwa') }}</label><input name="name" required maxlength="100" value="{{ $t?->name }}" placeholder="{{ __('np. Wasabi Frankfurt') }}"></div>
@if ($type === 's3')
    <div class="grid grid-3">
        <div class="field"><label>{{ __('Endpoint') }}</label><input name="config[endpoint]" type="url" required value="{{ $c['endpoint'] ?? '' }}" placeholder="https://s3.eu-central-1.wasabisys.com"></div>
        <div class="field"><label>{{ __('Region') }}</label><input name="config[region]" value="{{ $c['region'] ?? '' }}" placeholder="eu-central-1"></div>
        <div class="field"><label>{{ __('Bucket') }}</label><input name="config[bucket]" required value="{{ $c['bucket'] ?? '' }}"></div>
        <div class="field"><label>{{ __('Prefiks (katalog)') }}</label><input name="config[prefix]" value="{{ $c['prefix'] ?? '' }}" placeholder="virthub"></div>
        <div class="field"><label>{{ __('Access key') }}</label><input name="config[access_key]" required value="{{ $c['access_key'] ?? '' }}" autocomplete="off"></div>
        <div class="field"><label>{{ __('Secret key') }}</label><input name="config[secret_key]" type="password" autocomplete="new-password" @unless ($t) required @endunless placeholder="{{ $t ? __('bez zmiany') : '' }}"></div>
    </div>
@elseif ($type === 'sftp')
    <div class="grid grid-3">
        <div class="field"><label>{{ __('Host') }}</label><input name="config[host]" required value="{{ $c['host'] ?? '' }}"></div>
        <div class="field"><label>{{ __('Port') }}</label><input name="config[port]" type="number" min="1" max="65535" value="{{ $c['port'] ?? 22 }}"></div>
        <div class="field"><label>{{ __('Użytkownik') }}</label><input name="config[username]" required value="{{ $c['username'] ?? '' }}" autocomplete="off"></div>
        <div class="field"><label>{{ __('Hasło') }}</label><input name="config[password]" type="password" autocomplete="new-password" placeholder="{{ $t ? __('bez zmiany') : '' }}"></div>
        <div class="field"><label>{{ __('Katalog na serwerze') }}</label><input name="config[path]" required value="{{ $c['path'] ?? '/backups' }}"></div>
        <div class="field"><label>{{ __('Odcisk klucza hosta') }}</label><input name="config[host_key_fingerprint]" value="{{ $c['host_key_fingerprint'] ?? '' }}" placeholder="SHA256:…"></div>
    </div>
    <div class="field"><label>{{ __('Klucz prywatny (zamiast hasła)') }}</label><textarea name="config[private_key]" rows="3" spellcheck="false" style="font-family:var(--mono); font-size:12px" placeholder="{{ $t ? __('bez zmiany') : '-----BEGIN OPENSSH PRIVATE KEY-----' }}"></textarea>
        <div class="hint">{{ __('Odcisk klucza hosta (ssh-keyscan host | ssh-keygen -lf -) chroni przed podszyciem się pod serwer kopii.') }}</div></div>
@else
    <div class="field"><label>{{ __('Katalog na węźle') }}</label><input name="config[path]" required value="{{ $c['path'] ?? '/var/backups/virthub' }}">
        <div class="hint">{{ __('Na każdym węźle osobno — np. drugi dysk albo zamontowany NFS. Agent musi mieć do niego prawo zapisu.') }}</div></div>
@endif
<label class="check-line" style="margin-bottom:10px"><input type="checkbox" name="is_active" value="1" @checked($t?->is_active ?? true)> {{ __('Aktywny') }}</label>
