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
@elseif ($type === 'ftp')
    <div class="grid grid-3">
        <div class="field"><label>{{ __('Host') }}</label><input name="config[host]" required value="{{ $c['host'] ?? '' }}"></div>
        <div class="field"><label>{{ __('Port') }}</label><input name="config[port]" type="number" min="1" max="65535" value="{{ $c['port'] ?? 21 }}"></div>
        <div class="field"><label>{{ __('Użytkownik') }}</label><input name="config[username]" required value="{{ $c['username'] ?? '' }}" autocomplete="off"></div>
        <div class="field"><label>{{ __('Hasło') }}</label><input name="config[password]" type="password" autocomplete="new-password" placeholder="{{ $t ? __('bez zmiany') : '' }}"></div>
        <div class="field"><label>{{ __('Katalog na serwerze') }}</label><input name="config[path]" required value="{{ $c['path'] ?? '/backups' }}"></div>
        <div class="field"><label>{{ __('Szyfrowanie połączenia') }}</label>
            <input type="hidden" name="config[tls]" value="0">
            <label class="check-line"><input type="checkbox" name="config[tls]" value="1" @checked($c['tls'] ?? true)> {{ __('FTPS (AUTH TLS)') }}</label></div>
    </div>
@elseif ($type === 'rclone')
    <div class="grid grid-2">
        <div class="field"><label>{{ __('Backend rclone') }}</label><input name="config[backend]" required value="{{ $c['backend'] ?? '' }}" placeholder="b2, drive, onedrive, dropbox, swift…"></div>
        <div class="field"><label>{{ __('Katalog / bucket') }}</label><input name="config[path]" value="{{ $c['path'] ?? '' }}" placeholder="kopie/virthub"></div>
    </div>
    <div class="field"><label>{{ __('Parametry backendu') }}</label>
        <textarea name="config[params_text]" rows="4" spellcheck="false" style="font-family:var(--mono); font-size:12px" placeholder="{{ $t ? __('bez zmiany — wpisz wszystkie parametry, żeby je zastąpić') : "account = 0012ab34cd
key = K001xyz…" }}"></textarea>
        <div class="hint">{{ __('Jak w rclone.conf, bez nagłówka sekcji — po jednej parze „klucz = wartość” w wierszu. Węzeł musi mieć zainstalowany rclone; konfiguracja trafia na węzeł tylko na czas operacji.') }}</div></div>
@elseif ($type === 'pbs')
    <div class="grid grid-3">
        <div class="field"><label>{{ __('Host') }}</label><input name="config[host]" required value="{{ $c['host'] ?? '' }}"></div>
        <div class="field"><label>{{ __('Port') }}</label><input name="config[port]" type="number" min="1" max="65535" value="{{ $c['port'] ?? 8007 }}"></div>
        <div class="field"><label>{{ __('Magazyn (datastore)') }}</label><input name="config[datastore]" required value="{{ $c['datastore'] ?? '' }}"></div>
        <div class="field"><label>{{ __('Użytkownik albo token') }}</label><input name="config[username]" required value="{{ $c['username'] ?? '' }}" placeholder="backup@pbs!virthub" autocomplete="off"></div>
        <div class="field"><label>{{ __('Hasło / sekret tokenu') }}</label><input name="config[password]" type="password" autocomplete="new-password" @unless ($t) required @endunless placeholder="{{ $t ? __('bez zmiany') : '' }}"></div>
        <div class="field"><label>{{ __('Przestrzeń nazw') }} <span class="muted">{{ __('(opcjonalnie)') }}</span></label><input name="config[namespace]" value="{{ $c['namespace'] ?? '' }}"></div>
    </div>
    <div class="field"><label>{{ __('Odcisk certyfikatu PBS') }}</label><input name="config[fingerprint]" value="{{ $c['fingerprint'] ?? '' }}" placeholder="aa:bb:…:ff">
        <div class="hint">{{ __('Z panelu PBS (Dashboard → Show Fingerprint). Węzeł musi mieć proxmox-backup-client. PBS deduplikuje kopie między maszynami — szyfrowanie VirtHub poniżej tę deduplikację wyłącza.') }}</div></div>
@else
    <div class="field"><label>{{ __('Katalog na węźle') }}</label><input name="config[path]" required value="{{ $c['path'] ?? '/var/backups/virthub' }}">
        <div class="hint">{{ __('Na każdym węźle osobno — np. drugi dysk albo zamontowany NFS. Agent musi mieć do niego prawo zapisu.') }}</div></div>
@endif
@if ($t?->isEncrypted())
    <p class="hint"><span class="pill ok">{{ __('szyfrowanie AES-256') }}</span> {{ __('Klucz jest w bazie panelu — bez niego kopii nie da się odczytać. Uwzględnij bazę i APP_KEY w kopii systemu.') }}</p>
@else
    <label class="check-line"><input type="checkbox" name="encrypt" value="1"> {{ __('Szyfruj kopie na węźle (AES-256-GCM) przed wysyłką') }}</label>
@endif
<label class="check-line" style="margin-bottom:10px"><input type="checkbox" name="is_active" value="1" @checked($t?->is_active ?? true)> {{ __('Aktywny') }}</label>
