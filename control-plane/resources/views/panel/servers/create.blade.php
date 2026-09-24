@extends('layouts.panel')

@section('title', 'Zamów VPS')

@section('content')
    <h1>Zamów nowy VPS</h1>
    <p class="lede">Maszyna będzie gotowa zwykle w minutę od złożenia zamówienia.</p>

    <form method="POST" action="{{ route('panel.servers.store') }}">
        @csrf

        <div class="card">
            <h3>Pakiet zasobów</h3>
            <div class="field">
                <label for="package">Wybierz pakiet</label>
                <select id="package" name="package" required>
                    @foreach ($packages as $package)
                        <option value="{{ $package->slug }}" @selected(old('package') === $package->slug)>
                            {{ $package->name }} — {{ $package->vcpu }} vCPU,
                            {{ $package->ramGb() }} GB RAM,
                            {{ $package->disk_gb }} GB dysku,
                            {{ $package->bandwidth_gb }} GB transferu
                        </option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="card">
            <h3>System operacyjny</h3>
            @if ($osChoices->isEmpty())
                <p class="muted">
                    W tej chwili nie ma dostępnego systemu do zamówienia. Spróbuj za chwilę
                    albo skontaktuj się z obsługą.
                </p>
            @else
                @error('template') <div class="alert alert-error"><div>{{ $message }}</div></div> @enderror
                @include('panel.servers._os-picker', ['selected' => old('template')])
                @php
                    $types = $osChoices->flatMap(fn ($c) => $c['templates']->map(fn ($t) => $t->virtualization->value))
                        ->unique()->map(fn ($v) => \App\Enums\Virtualization::from($v));
                @endphp
                @if ($types->count() > 1)
                    @foreach ($types as $type)
                        <div class="hint"><strong>{{ $type->shortLabel() }}:</strong> {{ $type->description() }}</div>
                    @endforeach
                @endif
            @endif
        </div>

        @if ($locations->isNotEmpty())
            <div class="card">
                <h3>Lokalizacja</h3>
                <div class="field" style="margin-bottom:0">
                    <select id="location" name="location">
                        <option value="">Dowolna — wybierzemy najmniej obciążoną</option>
                        @foreach ($locations as $location)
                            <option value="{{ $location->id }}" @selected((int) old('location') === $location->id)>{{ $location->publicName() }}</option>
                        @endforeach
                    </select>
                    @error('location') <div class="hint" style="color:var(--critical)">{{ $message }}</div> @enderror
                </div>
            </div>
        @endif

        <div class="card">
            <h3>Konfiguracja maszyny</h3>

            <div class="field">
                <label for="hostname">Nazwa hosta</label>
                <input id="hostname" name="hostname" type="text" value="{{ old('hostname') }}"
                       placeholder="vps1.mojadomena.pl" required>
                <div class="hint">Trafia do konfiguracji systemu gościa. Musi być poprawną nazwą domenową.</div>
            </div>

            <div class="field">
                <label for="label">Nazwa własna <span class="muted">(opcjonalnie)</span></label>
                <input id="label" name="label" type="text" value="{{ old('label') }}"
                       placeholder="Serwer produkcyjny sklepu">
                <div class="hint">Widoczna tylko dla Ciebie, ułatwia rozpoznanie maszyny na liście.</div>
            </div>

            <div class="field">
                <label for="ssh_key">Klucz publiczny SSH <span class="muted">(opcjonalnie)</span></label>
                <textarea id="ssh_key" name="ssh_keys[]" rows="3"
                          placeholder="ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAI... twoj@komputer">{{ old('ssh_keys.0') }}</textarea>
                <div class="hint">
                    Możesz zostawić puste. Hasło roota generujemy zawsze i pokazujemy na stronie
                    maszyny zaraz po zamówieniu — zapisz je, po utworzeniu maszyny zniknie z panelu.
                </div>
            </div>
        </div>

        <div class="btn-row">
            <button class="btn btn-primary" type="submit">Zamawiam i tworzę maszynę</button>
            <a class="btn" href="{{ route('panel.dashboard') }}">Anuluj</a>
        </div>
    </form>

    @push('scripts')
        <script src="{{ asset('js/os-picker.js') }}?v={{ @filemtime(public_path('js/os-picker.js')) }}"></script>
    @endpush
@endsection
