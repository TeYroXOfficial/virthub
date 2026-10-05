@extends('layouts.panel')

@section('title', __('Plany aplikacji'))

@section('content')
    @include('panel.admin.apps._nav')

    @error('plan') <div class="alert alert-error">{{ $message }}</div> @enderror

    <details class="card" style="margin-bottom:16px" @if ($plans->isEmpty() || $errors->any()) open @endif>
        <summary><strong>{{ __('Nowy plan') }}</strong></summary>
        <form method="POST" action="{{ route('panel.admin.apps.plans.store') }}" style="margin-top:14px">
            @csrf
            <div class="grid-compact">
                <div class="field"><label for="p-name">{{ __('Nazwa') }}</label><input id="p-name" name="name" required value="{{ old('name') }}" placeholder="{{ __('Gra S') }}"></div>
                <div class="field"><label for="p-mem">{{ __('RAM (MB)') }}</label><input id="p-mem" name="memory_mb" inputmode="numeric" required value="{{ old('memory_mb', 2048) }}"></div>
                <div class="field"><label for="p-cpu">{{ __('Procesor (%)') }}</label><input id="p-cpu" name="cpu_percent" inputmode="numeric" value="{{ old('cpu_percent', 100) }}" placeholder="0"></div>
                <div class="field"><label for="p-disk">{{ __('Dysk (MB)') }}</label><input id="p-disk" name="disk_mb" inputmode="numeric" required value="{{ old('disk_mb', 10240) }}"></div>
                <div class="field"><label for="p-ports">{{ __('Porty') }}</label><input id="p-ports" name="ports" inputmode="numeric" required value="{{ old('ports', 1) }}"></div>
                <div class="field"><label for="p-price">{{ __('Cena (PLN)') }}</label><input id="p-price" name="price_hint" inputmode="decimal" value="{{ old('price_hint') }}" placeholder="{{ __('opcjonalnie') }}"></div>
            </div>
            <p class="hint">{{ __('Procesor: 100 = jeden rdzeń, 0 = bez limitu. Porty: ile portów z puli węzła dostaje aplikacja (pierwszy to port główny).') }}</p>
            @foreach (['name', 'memory_mb', 'cpu_percent', 'disk_mb', 'ports'] as $field)
                @error($field) <div class="hint" style="color:var(--critical)">{{ $message }}</div> @enderror
            @endforeach
            <button class="btn btn-primary" type="submit">{{ __('Dodaj plan') }}</button>
        </form>
    </details>

    <div class="card" style="padding:0">
        <div class="table-wrap">
            <table>
                <thead>
                <tr><th>{{ __('Plan') }}</th><th class="num">{{ __('RAM') }}</th><th class="num">{{ __('Procesor') }}</th><th class="num">{{ __('Dysk') }}</th><th class="num">{{ __('Porty') }}</th><th class="num">{{ __('Aplikacje') }}</th><th>{{ __('Status') }}</th><th></th></tr>
                </thead>
                <tbody>
                @forelse ($plans as $plan)
                    <tr>
                        <td><strong>{{ $plan->name }}</strong>
                            @if ($plan->price_hint_cents)<div class="hint">{{ number_format($plan->price_hint_cents / 100, 2, ',', ' ') }} {{ $plan->currency }}</div>@endif</td>
                        <td class="num">{{ $plan->memory_mb }} MB</td>
                        <td class="num">{{ $plan->cpu_percent ? $plan->cpu_percent.'%' : '∞' }}</td>
                        <td class="num">{{ round($plan->disk_mb / 1024, 1) }} GB</td>
                        <td class="num">{{ $plan->ports }}</td>
                        <td class="num">{{ $plan->servers_count }}</td>
                        <td><span class="pill {{ $plan->is_active ? 'ok' : 'neutral' }}">{{ $plan->is_active ? __('w sprzedaży') : __('wycofany') }}</span></td>
                        <td style="text-align:right; white-space:nowrap">
                            @php $bag = $errors->getBag('edit_'.$plan->id); $mine = $bag->any(); $v = fn ($f, $d) => $mine ? old($f, $d) : $d; @endphp
                            <button class="btn btn-sm" type="button" onclick="document.getElementById('plan-edit-{{ $plan->id }}').showModal()">{{ __('Edytuj') }}</button>
                            <dialog class="modal edit-modal" id="plan-edit-{{ $plan->id }}" @if ($mine) data-open @endif>
                                <form method="POST" action="{{ route('panel.admin.apps.plans.update', $plan) }}">
                                    @csrf @method('PUT')
                                    <h3 class="card-title">{{ __('Edytuj plan :name', ['name' => $plan->name]) }}</h3>
                                    @if ($mine) <div class="alert alert-error"><ul>@foreach ($bag->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div> @endif
                                    <div class="field"><label>{{ __('Nazwa') }}</label><input name="name" type="text" required maxlength="100" value="{{ $v('name', $plan->name) }}"></div>
                                    <div class="grid grid-3">
                                        <div class="field"><label>{{ __('RAM (MB)') }}</label><input name="memory_mb" type="number" min="128" required value="{{ $v('memory_mb', $plan->memory_mb) }}"></div>
                                        <div class="field"><label>{{ __('Procesor (%)') }}</label><input name="cpu_percent" type="number" min="0" value="{{ $v('cpu_percent', $plan->cpu_percent) }}"></div>
                                        <div class="field"><label>{{ __('Dysk (MB)') }}</label><input name="disk_mb" type="number" min="256" required value="{{ $v('disk_mb', $plan->disk_mb) }}"></div>
                                        <div class="field"><label>{{ __('Porty') }}</label><input name="ports" type="number" min="1" max="20" required value="{{ $v('ports', $plan->ports) }}"></div>
                                        <div class="field"><label>{{ __('Cena (PLN)') }}</label><input name="price_hint" type="text" inputmode="decimal" value="{{ $v('price_hint', $plan->price_hint_cents !== null ? number_format($plan->price_hint_cents / 100, 2, '.', '') : '') }}"></div>
                                    </div>
                                    @if ($plan->servers_count > 0)
                                        <label class="check-line"><input type="checkbox" name="apply_existing" value="1" @checked($mine && old('apply_existing'))>
                                            {{ __('Zastosuj RAM, procesor i dysk do istniejących aplikacji (:count)', ['count' => $plan->servers_count]) }}</label>
                                        <p class="hint">{{ __('Limity pamięci i procesora działają od razu, dysk po restarcie aplikacji. Liczba portów dotyczy tylko nowych aplikacji. Zmniejszenie dysku poniżej zajętego miejsca może zatrzymać zapis plików.') }}</p>
                                    @else
                                        <p class="hint">{{ __('Plan nie ma jeszcze aplikacji — zmiana dotyczy nowych zamówień.') }}</p>
                                    @endif
                                    <div class="btn-row" style="justify-content:flex-end">
                                        <button class="btn" type="button" onclick="this.closest('dialog').close()">{{ __('Anuluj') }}</button>
                                        <button class="btn btn-primary" type="submit">{{ __('Zapisz') }}</button>
                                    </div>
                                </form>
                            </dialog>
                            <form method="POST" action="{{ route('panel.admin.apps.plans.toggle', $plan) }}" style="display:inline">
                                @csrf
                                <button class="btn btn-sm" type="submit">{{ $plan->is_active ? __('Wycofaj') : __('Przywróć') }}</button>
                            </form>
                            @if ($plan->servers_count === 0)
                                <form method="POST" action="{{ route('panel.admin.apps.plans.destroy', $plan) }}" style="display:inline"
                                      data-confirm="{{ __('Usunąć plan :name?', ['name' => $plan->name]) }}">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-sm btn-danger" type="submit">{{ __('Usuń') }}</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="muted" style="text-align:center; padding:24px">{{ __('Brak planów.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @include('panel.admin._edit-modal-script')
@endsection
