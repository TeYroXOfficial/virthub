@extends('layouts.panel')

@section('title', __('Dostawcy zewnętrzni'))

@section('content')
    <div class="page-header"><div><h1>{{ __('Dostawcy zewnętrzni') }}</h1>
        <div class="meta-line"><span>{{ __('Odsprzedawaj VPS-y innych dostawców jako własne — przez addony z licencji.') }}</span></div></div></div>
    @error('account') <div class="alert alert-error">{{ $message }}</div> @enderror

    @if ($drivers === [])
        <div class="card"><p class="muted" style="margin:0">{{ __('Brak aktywnych sterowników dostawców. Zainstaluj addon (np. Onidel):') }}
            <a href="{{ route('panel.admin.license') }}">{{ __('Licencja i addony') }}</a></p></div>
    @else
        <details class="card" style="margin-bottom:16px" @if ($accounts->isEmpty() || $errors->has('credentials.*') || old('driver')) open @endif>
            <summary><strong>{{ __('Nowe konto dostawcy') }}</strong></summary>
            <form method="POST" action="{{ route('panel.admin.providers.store') }}" style="margin-top:14px" autocomplete="off">
                @csrf
                <div class="grid grid-2">
                    <div class="field"><label for="pa-driver">{{ __('Dostawca') }}</label>
                        <select id="pa-driver" name="driver" required>
                            @foreach ($drivers as $key => $d) <option value="{{ $key }}" @selected(old('driver') === $key)>{{ $d['name'] }}</option> @endforeach
                        </select></div>
                    <div class="field"><label for="pa-name">{{ __('Nazwa (widoczna tylko dla personelu)') }}</label>
                        <input id="pa-name" name="name" required maxlength="100" value="{{ old('name') }}" placeholder="{{ __('Onidel — konto główne') }}"></div>
                </div>
                @foreach ($drivers as $key => $d)
                    <div data-driver-fields="{{ $key }}">
                        @foreach ($d['class']::credentialFields() as $field => $meta)
                            <div class="field"><label for="pa-{{ $key }}-{{ $field }}">{{ $meta['label'] }}</label>
                                <input id="pa-{{ $key }}-{{ $field }}" name="credentials[{{ $field }}]" @if ($meta['secret'] ?? false) type="password" autocomplete="new-password" @endif>
                                @if (! empty($meta['help'])) <div class="hint">{{ $meta['help'] }}</div> @endif
                                @error('credentials.'.$field) <p class="hint" style="color:var(--critical)">{{ $message }}</p> @enderror</div>
                        @endforeach
                    </div>
                @endforeach
                <button class="btn btn-primary" type="submit">{{ __('Dodaj konto') }}</button>
                <span class="hint">{{ __('Panel sprawdza dane u dostawcy przed zapisem. Token jest szyfrowany w bazie.') }}</span>
            </form>
        </details>
    @endif

    <div class="card flush dash-section">
        <div class="dash-head"><h3 class="card-title" style="margin:0">{{ __('Konta') }}</h3></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>{{ __('Nazwa') }}</th><th>{{ __('Dostawca') }}</th><th class="num">{{ __('Serwery') }}</th><th class="num">{{ __('Produkty') }}</th><th>{{ __('Status') }}</th><th></th></tr></thead>
                <tbody>
                @forelse ($accounts as $account)
                    <tr>
                        <td><strong>{{ $account->name }}</strong></td>
                        <td>{{ $account->driverName() }} @unless ($account->driverAvailable()) <span class="pill warning">{{ __('addon niedostępny') }}</span> @endunless</td>
                        <td class="num">{{ $account->servers_count }}</td>
                        <td class="num">{{ $account->products_count }}</td>
                        <td><span class="pill {{ $account->is_active ? 'ok' : 'neutral' }}">{{ $account->is_active ? __('aktywne') : __('wyłączone') }}</span></td>
                        <td style="text-align:right; white-space:nowrap">
                            @if ($account->driverAvailable())
                                <form method="POST" action="{{ route('panel.admin.providers.test', $account) }}" style="display:inline; margin:0">@csrf <button class="btn btn-sm" type="submit">{{ __('Test') }}</button></form>
                            @endif
                            @php $bag = $errors->getBag('edit_'.$account->id); @endphp
                            <button class="btn btn-sm" type="button" onclick="document.getElementById('pa-edit-{{ $account->id }}').showModal()">{{ __('Edytuj') }}</button>
                            <dialog class="modal edit-modal" id="pa-edit-{{ $account->id }}" @if ($bag->any()) data-open @endif>
                                <form method="POST" action="{{ route('panel.admin.providers.update', $account) }}" autocomplete="off">
                                    @csrf @method('PUT')
                                    <h3 class="card-title">{{ __('Edytuj konto :name', ['name' => $account->name]) }}</h3>
                                    <div class="field"><label>{{ __('Nazwa') }}</label><input name="name" required maxlength="100" value="{{ $account->name }}"></div>
                                    @foreach (app(\App\Domain\External\ProviderRegistry::class)->fields($account->driver) as $field => $meta)
                                        <div class="field"><label>{{ $meta['label'] }}</label>
                                            <input name="credentials[{{ $field }}]" @if ($meta['secret'] ?? false) type="password" autocomplete="new-password" @endif placeholder="{{ __('bez zmian') }}"></div>
                                    @endforeach
                                    <input type="hidden" name="is_active" value="0">
                                    <label class="check-line"><input type="checkbox" name="is_active" value="1" @checked($account->is_active)> {{ __('Aktywne — nowe zamówienia mogą trafiać na to konto') }}</label>
                                    <div class="btn-row" style="justify-content:flex-end">
                                        <button class="btn" type="button" onclick="this.closest('dialog').close()">{{ __('Anuluj') }}</button>
                                        <button class="btn btn-primary" type="submit">{{ __('Zapisz') }}</button>
                                    </div>
                                </form>
                            </dialog>
                            @if ($account->servers_count === 0 && $account->products_count === 0)
                                <form method="POST" action="{{ route('panel.admin.providers.destroy', $account) }}" style="display:inline; margin:0" data-confirm="{{ __('Usunąć konto :name?', ['name' => $account->name]) }}">
                                    @csrf @method('DELETE') <button class="btn btn-sm btn-danger" type="submit">{{ __('Usuń') }}</button></form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="muted" style="text-align:center; padding:24px">{{ __('Brak kont dostawców.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card flush" style="margin-top:16px">
        <div class="dash-head"><h3 class="card-title" style="margin:0">{{ __('Ostatnie serwery u dostawców') }}</h3></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>{{ __('Serwer') }}</th><th>{{ __('Klient') }}</th><th>{{ __('Konto') }}</th><th>{{ __('Adres IP') }}</th><th>{{ __('Stan') }}</th></tr></thead>
                <tbody>
                @forelse ($servers as $vm)
                    <tr>
                        <td><a href="{{ route('panel.cloud.show', $vm) }}">{{ $vm->name }}</a> <span class="hint mono">{{ $vm->remote_id }}</span></td>
                        <td>{{ $vm->user?->email ?? '—' }}</td>
                        <td>{{ $vm->account?->name }}</td>
                        <td class="mono">{{ $vm->ipv4 ?? '—' }}</td>
                        <td><span class="pill {{ $vm->statusTone() }}">{{ $vm->statusLabel() }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="muted" style="text-align:center; padding:24px">{{ __('Brak serwerów.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @include('panel.admin._edit-modal-script')
@endsection

@push('scripts')
    <script>
        (function () {
            var sel = document.getElementById('pa-driver');
            function sync() {
                document.querySelectorAll('[data-driver-fields]').forEach(function (box) {
                    var on = sel && box.dataset.driverFields === sel.value;
                    box.hidden = !on;
                    box.querySelectorAll('input').forEach(function (i) { i.disabled = !on; });
                });
            }
            if (sel) { sel.addEventListener('change', sync); sync(); }
        })();
    </script>
@endpush
