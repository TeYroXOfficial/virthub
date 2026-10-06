@extends('layouts.panel')

@section('title', __('Licencja i addony'))

@php
    use App\Domain\Licensing\LicenseManager as L;
    $state = $status['state'];
    $payload = $status['payload'] ?? [];
    [$tone, $label] = match ($state) {
        L::STATE_VALID => ['ok', __('aktywna')],
        L::STATE_NONE => ['neutral', __('brak klucza')],
        L::STATE_UNCONFIGURED => ['neutral', __('serwer licencji nieskonfigurowany')],
        L::STATE_EXPIRED => ['critical', __('wygasła')],
        L::STATE_SUSPENDED => ['critical', __('zawieszona')],
        L::STATE_STALE => ['warning', __('brak kontaktu z serwerem licencji')],
        L::STATE_DOMAIN => ['critical', __('wystawiona dla innej domeny')],
        default => ['critical', __('nieprawidłowa')],
    };
@endphp

@section('content')
    <div class="page-header"><div><h1>{{ __('Licencja i addony') }}</h1></div></div>
    @error('key') <div class="alert alert-error">{{ $message }}</div> @enderror
    @error('addon') <div class="alert alert-error">{{ $message }}</div> @enderror

    <div class="grid grid-2">
        <div class="card">
            <h3 class="card-title"><x-icon name="key" :size="16"/> {{ __('Licencja') }} <span class="pill {{ $tone }}">{{ $label }}</span></h3>
            @if (! $configured)
                <p class="muted">{{ __('Panel nie ma adresu ani klucza publicznego serwera licencji (VIRTHUB_LICENSE_SERVER, VIRTHUB_LICENSE_PUBLIC_KEY w .env). Panel działa normalnie, tylko bez addonów.') }}</p>
            @else
                <dl class="kv">
                    <dt>{{ __('Domena panelu') }}</dt><dd class="mono">{{ $domain }}</dd>
                    @if ($key) <dt>{{ __('Klucz') }}</dt><dd class="mono">{{ $key }}</dd> @endif
                    @if (! empty($payload['licensee'])) <dt>{{ __('Właściciel') }}</dt><dd>{{ $payload['licensee'] }}</dd> @endif
                    @if ($payload)
                        <dt>{{ __('Ważna do') }}</dt><dd>{{ ! empty($payload['expires_at']) ? \Illuminate\Support\Carbon::parse($payload['expires_at'])->format('Y-m-d') : __('bezterminowo') }}</dd>
                    @endif
                    @if ($status['checked_at']) <dt>{{ __('Sprawdzona') }}</dt><dd>{{ \Illuminate\Support\Carbon::createFromTimestamp($status['checked_at'])->diffForHumans() }}</dd> @endif
                </dl>
                @if ($status['error']) <p class="hint" style="color:var(--critical)">{{ $status['error'] }}</p> @endif
                <form method="POST" action="{{ route('panel.admin.license.activate') }}" class="filter-bar" style="margin-top:12px">
                    @csrf
                    <div class="field" style="margin:0; flex:1"><label for="l-key">{{ $key ? __('Nowy klucz licencji') : __('Klucz licencji') }}</label>
                        <input id="l-key" name="key" autocomplete="off" placeholder="VH-XXXX-XXXX-XXXX-XXXX" required></div>
                    <button class="btn btn-primary" type="submit">{{ __('Aktywuj') }}</button>
                </form>
                @if ($key)
                    <div class="btn-row" style="margin-top:8px">
                        <form method="POST" action="{{ route('panel.admin.license.refresh') }}" style="margin:0">@csrf <button class="btn btn-sm" type="submit">{{ __('Sprawdź teraz') }}</button></form>
                        <form method="POST" action="{{ route('panel.admin.license.remove') }}" style="margin:0" data-confirm="{{ __('Usunąć klucz licencji? Addony zostaną wyłączone.') }}">
                            @csrf @method('DELETE') <button class="btn btn-sm btn-ghost" type="submit">{{ __('Usuń klucz') }}</button></form>
                    </div>
                @endif
            @endif
        </div>
        <div class="card">
            <h3 class="card-title">{{ __('Jak to działa') }}</h3>
            <ul class="plain-list">
                <li>{{ __('Licencja jest przypisana do domeny panelu. Panel sprawdza ją co 12 godzin; bez kontaktu z serwerem licencji działa jeszcze 7 dni.') }}</li>
                <li>{{ __('Addony instalowane są z serwera licencji jako paczki podpisane cyfrowo — panel odrzuca każdą paczkę bez poprawnego podpisu.') }}</li>
                <li>{{ __('Wyłączenie addonu albo wygaśnięcie licencji nie usuwa serwerów klientów — wstrzymuje tylko nowe zamówienia i akcje.') }}</li>
            </ul>
        </div>
    </div>

    <div class="card flush" style="margin-top:16px">
        <div class="dash-head"><h3 class="card-title" style="margin:0"><x-icon name="puzzle" :size="16"/> {{ __('Addony') }}</h3></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>{{ __('Addon') }}</th><th>{{ __('Licencja') }}</th><th>{{ __('Zainstalowany') }}</th><th>{{ __('Stan') }}</th><th></th></tr></thead>
                <tbody>
                @forelse ($catalog as $addon)
                    @php
                        $id = $addon['id'];
                        $inst = $installed[$id] ?? null;
                        $update = $inst && ! empty($addon['version']) && version_compare($addon['version'], $inst['version'], '>');
                    @endphp
                    <tr>
                        <td><strong>{{ $addon['name'] }}</strong> <span class="hint mono">{{ $id }}</span>
                            @if (! empty($addon['description'])) <div class="hint">{{ $addon['description'] }}</div> @endif</td>
                        <td>@if ($addon['owned'] ?? false) <span class="pill ok">{{ __('wykupiony') }}</span> @else <span class="pill neutral">{{ __('niewykupiony') }}</span> @if (! empty($addon['price'])) <span class="hint">{{ $addon['price'] }}</span> @endif @endif</td>
                        <td class="mono">{{ $inst['version'] ?? '—' }} @if ($update) <span class="pill info plain">{{ __('nowa: :v', ['v' => $addon['version']]) }}</span> @endif</td>
                        <td>
                            @if (isset($loaded[$id])) <span class="pill ok">{{ __('działa') }}</span>
                            @elseif ($inst && ! $inst['enabled']) <span class="pill neutral">{{ __('wyłączony') }}</span>
                            @elseif ($inst) <span class="pill warning">{{ __('nieaktywny (licencja)') }}</span>
                            @else — @endif
                        </td>
                        <td style="text-align:right; white-space:nowrap">
                            @if (($addon['owned'] ?? false) && (! $inst || $update))
                                <form method="POST" action="{{ route('panel.admin.addons.install', $id) }}" style="display:inline; margin:0">@csrf
                                    <button class="btn btn-sm btn-primary" type="submit">{{ $inst ? __('Aktualizuj') : __('Zainstaluj') }}</button></form>
                            @endif
                            @if ($inst)
                                <form method="POST" action="{{ route('panel.admin.addons.toggle', $id) }}" style="display:inline; margin:0">@csrf
                                    <button class="btn btn-sm" type="submit">{{ $inst['enabled'] ? __('Wyłącz') : __('Włącz') }}</button></form>
                                <form method="POST" action="{{ route('panel.admin.addons.uninstall', $id) }}" style="display:inline; margin:0"
                                      data-confirm="{{ __('Odinstalować addon :name? Serwery klientów u tego dostawcy przestaną być zarządzane z panelu, dopóki go nie zainstalujesz ponownie.', ['name' => $addon['name']]) }}">
                                    @csrf @method('DELETE') <button class="btn btn-sm btn-ghost" type="submit" aria-label="{{ __('Odinstaluj') }}"><x-icon name="trash" :size="14"/></button></form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="muted" style="text-align:center; padding:24px">{{ $state === L::STATE_VALID ? __('Brak dostępnych addonów.') : __('Aktywuj licencję, żeby zobaczyć dostępne addony.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
