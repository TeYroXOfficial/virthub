@extends('layouts.panel')

@section('title', __('Pakiety zasobów'))

@section('content')
    <div class="page-header"><div><h1>{{ __('Pakiety zasobów') }}</h1>
        <p class="lede">{{ __('Samoobsługa: klient dostaje budżet vCPU, RAM i dysku i sam buduje oraz usuwa maszyny w jego ramach. Opcjonalne stawki godzinowe pobierane są z portfela; brak środków zawiesza maszyny pakietu do doładowania.') }}</p></div></div>
    @error('pack') <div class="alert alert-error"><div>{{ $message }}</div></div> @enderror

    @foreach ($packs as $pack)
        @php $used = $pack->used(); @endphp
        <details class="card">
            <summary style="display:flex; gap:10px; align-items:center; flex-wrap:wrap">
                <strong>{{ $pack->name }}</strong> <span class="muted">{{ $pack->user?->email }}</span>
                <span>{{ $used['vcpu'] }}/{{ $pack->vcpu }} vCPU · {{ $used['ram_mb'] }}/{{ $pack->ram_mb }} MB · {{ $used['disk_gb'] }}/{{ $pack->disk_gb }} GB · {{ $used['servers'] }}/{{ $pack->max_servers }}</span>
                @if ($pack->suspended_at) <span class="pill critical">{{ __('zawieszony') }}</span> @endif
                @unless ($pack->is_active) <span class="pill neutral">{{ __('wyłączony') }}</span> @endunless
            </summary>
            <form method="POST" action="{{ route('panel.admin.resource-packs.update', $pack) }}" style="margin-top:14px">
                @csrf @method('PUT')
                @include('panel.admin._pack-fields', ['pack' => $pack])
                <button class="btn btn-primary" type="submit">{{ __('Zapisz') }}</button>
            </form>
            <form method="POST" action="{{ route('panel.admin.resource-packs.destroy', $pack) }}" style="margin-top:8px" data-confirm="{{ __('Usunąć pakiet zasobów?') }}">
                @csrf @method('DELETE') <button class="btn btn-sm btn-danger" type="submit" @disabled($pack->servers_count > 0)>{{ __('Usuń pakiet') }}</button>
            </form>
        </details>
    @endforeach

    <details class="card" @if ($packs->isEmpty() || $errors->any()) open @endif>
        <summary><strong>{{ __('Nowy pakiet zasobów') }}</strong></summary>
        <form method="POST" action="{{ route('panel.admin.resource-packs.store') }}" style="margin-top:14px">
            @csrf
            @include('panel.admin._pack-fields', ['pack' => null])
            @error('email') <div class="hint" style="color:var(--critical)">{{ $message }}</div> @enderror
            <button class="btn btn-primary" type="submit">{{ __('Przydziel pakiet') }}</button>
        </form>
    </details>
@endsection
