{{-- Pola serwera baz danych ($h — edytowany serwer lub null, $v — wartość pola). --}}
<div class="grid grid-2">
    <div class="field"><label>{{ __('Nazwa') }}</label><input name="name" required maxlength="100" value="{{ $v('name', $h?->name) }}" placeholder="{{ __('MariaDB węzeł 1') }}"></div>
    <div class="field"><label>{{ __('Węzeł') }}</label>
        <select name="hypervisor_id">
            <option value="">{{ __('wszystkie węzły') }}</option>
            @foreach ($nodes as $node)
                <option value="{{ $node->id }}" @selected((string) $v('hypervisor_id', $h?->hypervisor_id) === (string) $node->id)>{{ $node->name }}</option>
            @endforeach
        </select></div>
    <div class="field"><label>{{ __('Host (połączenie panelu)') }}</label><input name="host" required maxlength="255" value="{{ $v('host', $h?->host) }}" placeholder="10.0.0.5"></div>
    <div class="field"><label>{{ __('Port') }}</label><input name="port" type="number" min="1" max="65535" required value="{{ $v('port', $h?->port ?? 3306) }}"></div>
    <div class="field"><label>{{ __('Adres dla klientów') }}</label><input name="public_host" maxlength="255" value="{{ $v('public_host', $h?->public_host) }}" placeholder="{{ __('jak host') }}"></div>
    <div class="field"><label>{{ __('Limit baz') }}</label><input name="max_databases" type="number" min="1" value="{{ $v('max_databases', $h?->max_databases) }}" placeholder="{{ __('bez limitu') }}"></div>
    <div class="field"><label>{{ __('Użytkownik administracyjny') }}</label><input name="username" required maxlength="64" value="{{ $v('username', $h?->username) }}" autocomplete="off"></div>
    <div class="field"><label>{{ __('Hasło') }}</label><input name="password" type="password" maxlength="255" @if (! $h) required @endif autocomplete="new-password" placeholder="{{ $h ? __('bez zmian') : '' }}"></div>
</div>
<input type="hidden" name="is_active" value="0">
<label class="check-line"><input type="checkbox" name="is_active" value="1" @checked($v('is_active', $h?->is_active ?? true))> {{ __('Aktywny — nowe bazy mogą trafiać na ten serwer') }}</label>
<p class="hint">{{ __('Panel sprawdza połączenie przed zapisem. Bazy aplikacji z wybranego węzła trafiają tu w pierwszej kolejności.') }}</p>
