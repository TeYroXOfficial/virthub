@extends('layouts.panel')

@section('title', __('Kopie zapasowe'))

@section('content')
    <div class="page-header"><div><h1>{{ __('Kopie zapasowe') }}</h1>
        <p class="lede">{{ __('Kopie dysków maszyn KVM na zewnętrzne cele — katalog na węźle, S3 (AWS, MinIO, Wasabi, Backblaze…) albo SFTP — według planów z retencją. Działająca maszyna nie jest zatrzymywana: dysk jest na chwilę zamrażany nakładką.') }}</p></div></div>
    @error('target') <div class="alert alert-error"><div>{{ $message }}</div></div> @enderror

    <h2>{{ __('Cele') }}</h2>
    @foreach ($targets as $target)
        <details class="card">
            <summary style="display:flex; gap:10px; align-items:center; flex-wrap:wrap">
                <strong>{{ $target->name }}</strong> <span class="pill neutral plain">{{ $target->type }}</span>
                <code class="muted">{{ $target->describe() }}</code>
                @unless ($target->is_active) <span class="pill neutral">{{ __('wyłączony') }}</span> @endunless
            </summary>
            <form method="POST" action="{{ route('panel.admin.backups.targets.update', $target) }}" style="margin-top:14px" autocomplete="off">
                @csrf @method('PUT')
                @include('panel.admin._backup-target-fields', ['target' => $target, 'type' => $target->type])
                <button class="btn btn-primary" type="submit">{{ __('Zapisz') }}</button>
            </form>
            <form method="POST" action="{{ route('panel.admin.backups.targets.destroy', $target) }}" style="margin-top:8px" data-confirm="{{ __('Usunąć cel? Pliki kopii w nim zostaną.') }}">
                @csrf @method('DELETE') <button class="btn btn-sm btn-danger" type="submit">{{ __('Usuń cel') }}</button>
            </form>
        </details>
    @endforeach
    <details class="card" @if ($targets->isEmpty()) open @endif>
        <summary><strong>{{ __('Nowy cel') }}</strong></summary>
        <div class="btn-row" style="margin:12px 0">
            @foreach (['local' => __('Katalog na węźle'), 's3' => 'S3', 'sftp' => 'SFTP'] as $type => $label)
                <a class="btn btn-sm @if (request('type', 'local') === $type) btn-primary @endif" href="{{ route('panel.admin.backups', ['type' => $type]) }}">{{ $label }}</a>
            @endforeach
        </div>
        <form method="POST" action="{{ route('panel.admin.backups.targets.store') }}" autocomplete="off">
            @csrf
            <input type="hidden" name="type" value="{{ in_array(request('type'), ['local', 's3', 'sftp'], true) ? request('type') : 'local' }}">
            @include('panel.admin._backup-target-fields', ['target' => null, 'type' => in_array(request('type'), ['local', 's3', 'sftp'], true) ? request('type') : 'local'])
            <button class="btn btn-primary" type="submit">{{ __('Dodaj cel') }}</button>
        </form>
    </details>

    <h2>{{ __('Plany') }}</h2>
    @foreach ($plans as $plan)
        <details class="card">
            <summary style="display:flex; gap:10px; align-items:center; flex-wrap:wrap">
                <strong>{{ $plan->name }}</strong>
                <span class="muted">{{ __('co :h h, :keep kopii → :target', ['h' => $plan->interval_hours, 'keep' => $plan->retention, 'target' => $plan->target?->name]) }}</span>
                <span class="muted">{{ trans_choice(':count maszyna|:count maszyny|:count maszyn', $plan->servers_count) }}</span>
                @unless ($plan->is_active) <span class="pill neutral">{{ __('wyłączony') }}</span> @endunless
            </summary>
            <form method="POST" action="{{ route('panel.admin.backups.plans.update', $plan) }}" style="margin-top:14px">
                @csrf @method('PUT')
                @include('panel.admin._backup-plan-fields', ['plan' => $plan])
                <button class="btn btn-primary" type="submit">{{ __('Zapisz') }}</button>
            </form>
            <form method="POST" action="{{ route('panel.admin.backups.plans.destroy', $plan) }}" style="margin-top:8px" data-confirm="{{ __('Usunąć plan? Istniejące kopie zostaną.') }}">
                @csrf @method('DELETE') <button class="btn btn-sm btn-danger" type="submit">{{ __('Usuń plan') }}</button>
            </form>
        </details>
    @endforeach
    @if ($targets->isNotEmpty())
        <details class="card" @if ($plans->isEmpty()) open @endif>
            <summary><strong>{{ __('Nowy plan') }}</strong></summary>
            <form method="POST" action="{{ route('panel.admin.backups.plans.store') }}" style="margin-top:14px">
                @csrf
                @include('panel.admin._backup-plan-fields', ['plan' => null])
                <button class="btn btn-primary" type="submit">{{ __('Dodaj plan') }}</button>
            </form>
            <p class="hint">{{ __('Plan przypisujesz maszynie na jej stronie (Ustawienia) albo pakietowi VPS — wtedy dostaje go każda nowa maszyna.') }}</p>
        </details>
    @endif

    <div class="card flush dash-section">
        <div class="dash-head"><h3 class="card-title" style="margin:0">{{ __('Ostatnie kopie') }}</h3></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>{{ __('Maszyna') }}</th><th>{{ __('Kopia') }}</th><th>{{ __('Cel') }}</th><th>{{ __('Rozmiar') }}</th><th>{{ __('Status') }}</th></tr></thead>
                <tbody>
                @forelse ($recent as $backup)
                    <tr>
                        <td>{{ $backup->server?->hostname ?? '—' }}</td>
                        <td><code>{{ $backup->name }}</code> <span class="muted">{{ $backup->created_at->diffForHumans() }}</span></td>
                        <td>{{ $backup->target?->name ?? '—' }}</td>
                        <td>{{ $backup->size_mb ? $backup->size_mb.' MB' : '—' }}</td>
                        <td><span class="pill {{ ['ready' => 'ok', 'creating' => 'warning', 'failed' => 'critical'][$backup->status] ?? 'neutral' }}">{{ $backup->status }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="muted">{{ __('Jeszcze nie ma kopii.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
