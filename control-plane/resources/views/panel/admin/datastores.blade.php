@extends('layouts.panel')

@section('title', __('Magazyny danych'))

@section('content')
    <div class="page-header"><div><h1>{{ __('Magazyny danych') }}</h1>
        <p class="lede">{{ __('Gdzie leżą dyski maszyn KVM: katalog z plikami qcow2 (cienki albo gruby) albo pula Ceph RBD współdzielona przez węzły. Magazyn przypinasz do węzłów i do pakietu VPS — nowe maszyny z pakietu powstają w nim. Bez magazynu dysk trafia do katalogu domyślnego węzła.') }}</p></div></div>

    @foreach ($datastores as $ds)
        @php $cap = $ds->capacity(); $used = $cap['total_gb'] ? (int) round(100 * ($cap['total_gb'] - $cap['free_gb']) / max(1, $cap['total_gb'])) : null; @endphp
        <details class="card">
            <summary style="display:flex; gap:10px; align-items:center; flex-wrap:wrap">
                <strong>{{ $ds->name }}</strong>
                <span class="pill neutral plain">{{ $ds->typeLabel() }}</span>
                @unless ($ds->isCeph()) <span class="pill neutral plain">{{ $ds->thin ? __('cienki') : __('gruby') }}</span> @endunless
                <code class="muted">{{ $ds->describe() }}</code>
                @unless ($ds->is_active) <span class="pill neutral">{{ __('wyłączony') }}</span> @endunless
                <span class="muted" style="margin-left:auto">
                    {{ trans_choice(':count maszyna|:count maszyny|:count maszyn', $ds->servers_count) }} ·
                    @if ($used !== null) {{ __(':free GB wolne z :total GB', ['free' => $cap['free_gb'], 'total' => $cap['total_gb']]) }} @else {{ __('brak odczytu') }} @endif
                </span>
            </summary>

            @if ($used !== null)
                <div class="meter @if ($used >= 85) hot @endif" title="{{ $used }}%"><i style="width: {{ $used }}%"></i></div>
            @endif

            <div class="table-wrap" style="margin-top:14px">
                <table>
                    <thead><tr><th>{{ __('Węzeł') }}</th><th>{{ __('Pojemność') }}</th><th>{{ __('Wolne') }}</th><th>{{ __('Stan') }}</th><th>{{ __('Sprawdzono') }}</th></tr></thead>
                    <tbody>
                    @forelse ($ds->hypervisors as $node)
                        <tr>
                            <td><a href="{{ route('panel.admin.hypervisors.show', $node) }}">{{ $node->name }}</a></td>
                            <td>{{ $node->pivot->total_gb !== null ? $node->pivot->total_gb.' GB' : '—' }}</td>
                            <td>{{ $node->pivot->free_gb !== null ? $node->pivot->free_gb.' GB' : '—' }}</td>
                            <td>
                                @if ($node->pivot->ok === null) <span class="pill neutral">{{ __('nie sprawdzono') }}</span>
                                @elseif ($node->pivot->ok) <span class="pill ok">{{ __('dostępny') }}</span>
                                @else <span class="pill critical">{{ __('błąd') }}</span> <div class="hint">{{ $node->pivot->error }}</div>
                                @endif
                            </td>
                            <td class="muted">{{ $node->pivot->checked_at ? \Illuminate\Support\Carbon::parse($node->pivot->checked_at)->diffForHumans() : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="muted">{{ __('Magazyn nie jest przypięty do żadnego węzła.') }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            <form method="POST" action="{{ route('panel.admin.datastores.refresh', $ds) }}" style="margin:10px 0">
                @csrf <button class="btn btn-sm" type="submit"><x-icon name="refresh" :size="14"/> {{ __('Odśwież pojemność') }}</button>
            </form>
            @if ($ds->isCeph())
                <p class="hint">{{ __('Dyski w Cephie: migawki RBD działają; Elevate i kopie na zewnętrzne cele wymagają magazynu katalogowego.') }}</p>
            @endif

            <form method="POST" action="{{ route('panel.admin.datastores.update', $ds) }}" style="margin-top:14px" autocomplete="off">
                @csrf @method('PUT')
                @include('panel.admin._datastore-fields', ['ds' => $ds, 'type' => $ds->type])
                <p class="hint">{{ __('Zmiana ścieżki, puli albo klucza dotyczy nowych maszyn — istniejące zachowują dysk tam, gdzie powstał.') }}</p>
                <button class="btn btn-primary" type="submit">{{ __('Zapisz') }}</button>
            </form>
            <form method="POST" action="{{ route('panel.admin.datastores.destroy', $ds) }}" style="margin-top:8px" data-confirm="{{ __('Usunąć magazyn :name? Dane na dyskach nie są kasowane.', ['name' => $ds->name]) }}">
                @csrf @method('DELETE') <button class="btn btn-sm btn-danger" type="submit">{{ __('Usuń magazyn') }}</button>
            </form>
        </details>
    @endforeach

    <details class="card" @if ($datastores->isEmpty() || request()->has('type')) open @endif>
        <summary><strong>{{ __('Nowy magazyn') }}</strong></summary>
        <div class="btn-row" style="margin:12px 0">
            @foreach (['directory' => __('Katalog (qcow2)'), 'ceph' => 'Ceph RBD'] as $type => $label)
                <a class="btn btn-sm @if ($newType === $type) btn-primary @endif" href="{{ route('panel.admin.datastores', ['type' => $type]) }}">{{ $label }}</a>
            @endforeach
        </div>
        <form method="POST" action="{{ route('panel.admin.datastores.store') }}" autocomplete="off">
            @csrf
            <input type="hidden" name="type" value="{{ $newType }}">
            @include('panel.admin._datastore-fields', ['ds' => null, 'type' => $newType])
            @if ($newType === 'ceph')
                <p class="hint">{{ __('Węzły potrzebują pakietów ceph-common i qemu-block-extra (instalator węzła dodaje je sam). Szablon systemu jest importowany do puli przy pierwszej maszynie, kolejne to jego klony — tworzą się w sekundy.') }}</p>
            @endif
            <button class="btn btn-primary" type="submit">{{ __('Dodaj magazyn') }}</button>
        </form>
    </details>
@endsection
