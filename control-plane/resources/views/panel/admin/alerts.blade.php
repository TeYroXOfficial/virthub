@extends('layouts.panel')

@section('title', __('Alerty'))

@section('content')
    <div class="page-header"><div><h1>{{ __('Alerty') }}</h1>
        <p class="lede">{{ __('Progi dla maszyn i hypervisorów: CPU, RAM, dysk, sieć, transfer, brak kontaktu z węzłem, nieudane migawki. Panel sprawdza je co minutę i powiadamia e-mailem oraz webhookiem.') }}</p></div></div>

    <div class="card flush dash-section">
        <div class="dash-head"><h3 class="card-title" style="margin:0">{{ __('Otwarte alerty') }}
            @if ($open->isNotEmpty()) <span class="pill critical">{{ $open->count() }}</span> @endif</h3></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>{{ __('Reguła') }}</th><th>{{ __('Obiekt') }}</th><th>{{ __('Wartość') }}</th><th>{{ __('Szczyt') }}</th><th>{{ __('Od') }}</th></tr></thead>
                <tbody>
                @forelse ($open as $incident)
                    <tr>
                        <td><strong>{{ $incident->rule?->name }}</strong></td>
                        <td>{{ $incident->subject_label }}</td>
                        <td><span class="pill critical">{{ \App\Domain\Metrics\Alerts::format($incident->rule, $incident->value) }}</span></td>
                        <td>{{ \App\Domain\Metrics\Alerts::format($incident->rule, $incident->peak) }}</td>
                        <td>{{ $incident->started_at->diffForHumans() }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="muted">{{ __('Wszystko w normie.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <h2>{{ __('Reguły') }}</h2>
    @foreach ($rules as $rule)
        <details class="card">
            <summary style="display:flex; gap:10px; align-items:center; flex-wrap:wrap">
                <strong>{{ $rule->name }}</strong>
                <span class="muted">{{ $metrics[$rule->metric]['label'] ?? $rule->metric }} {{ \App\Domain\Metrics\Alerts::thresholdLabel($rule) }}</span>
                <span class="muted">{{ $rule->hypervisor?->name ?? __('wszystkie węzły') }}</span>
                @unless ($rule->is_active) <span class="pill neutral">{{ __('wyłączona') }}</span> @endunless
                @if ($rule->open_count) <span class="pill critical">{{ trans_choice(':count otwarty|:count otwarte|:count otwartych', $rule->open_count) }}</span> @endif
            </summary>
            <form method="POST" action="{{ route('panel.admin.alerts.update', $rule) }}" style="margin-top:14px">
                @csrf @method('PUT')
                @include('panel.admin._alert-fields', ['rule' => $rule, 'suffix' => '-'.$rule->id])
                <button class="btn btn-primary" type="submit">{{ __('Zapisz') }}</button>
            </form>
            <form method="POST" action="{{ route('panel.admin.alerts.destroy', $rule) }}" style="margin-top:8px" data-confirm="{{ __('Usunąć regułę razem z historią alertów?') }}">
                @csrf @method('DELETE') <button class="btn btn-sm btn-danger" type="submit">{{ __('Usuń regułę') }}</button>
            </form>
        </details>
    @endforeach

    <details class="card" @if ($rules->isEmpty() || $errors->any()) open @endif>
        <summary><strong>{{ __('Nowa reguła') }}</strong></summary>
        <form method="POST" action="{{ route('panel.admin.alerts.store') }}" style="margin-top:14px">
            @csrf
            @include('panel.admin._alert-fields', ['rule' => null, 'suffix' => ''])
            <button class="btn btn-primary" type="submit">{{ __('Dodaj regułę') }}</button>
        </form>
    </details>

    <div class="card flush dash-section">
        <div class="dash-head"><h3 class="card-title" style="margin:0">{{ __('Historia (ostatnie 50)') }}</h3></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>{{ __('Reguła') }}</th><th>{{ __('Obiekt') }}</th><th>{{ __('Szczyt') }}</th><th>{{ __('Początek') }}</th><th>{{ __('Czas trwania') }}</th></tr></thead>
                <tbody>
                @forelse ($history as $incident)
                    <tr>
                        <td>{{ $incident->rule?->name }}</td>
                        <td>{{ $incident->subject_label }}</td>
                        <td>{{ \App\Domain\Metrics\Alerts::format($incident->rule, $incident->peak) }}</td>
                        <td>{{ $incident->started_at->format('d.m.Y H:i') }}</td>
                        <td>{{ $incident->started_at->diffForHumans($incident->resolved_at, true) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="muted">{{ __('Brak zamkniętych alertów.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
