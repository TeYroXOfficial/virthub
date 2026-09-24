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

            <div class="field">
                <label for="template">System operacyjny</label>
                @if ($templateGroups->isEmpty())
                    <p class="muted">
                        W tej chwili nie ma dostępnego systemu do zamówienia. Spróbuj za chwilę
                        albo skontaktuj się z obsługą.
                    </p>
                @else
                    <select id="template" name="template" required>
                        @foreach ($templateGroups as $type => $templates)
                            <optgroup label="{{ \App\Enums\Virtualization::from($type)->label() }}">
                                @foreach ($templates as $template)
                                    <option value="{{ $template->id }}" @selected((int) old('template') === $template->id)>
                                        {{ $template->name }}
                                    </option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                    @foreach ($templateGroups->keys() as $type)
                        <div class="hint">
                            <strong>{{ \App\Enums\Virtualization::from($type)->shortLabel() }}:</strong>
                            {{ \App\Enums\Virtualization::from($type)->description() }}
                        </div>
                    @endforeach
                @endif
            </div>
        </div>

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
                <label for="ssh_key">Klucz publiczny SSH <span class="muted">(zalecane)</span></label>
                <textarea id="ssh_key" name="ssh_keys[]" rows="3"
                          placeholder="ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAI... twoj@komputer">{{ old('ssh_keys.0') }}</textarea>
                <div class="hint">
                    Bez klucza dostaniesz jednorazowe hasło roota — zobaczysz je tylko raz,
                    zaraz po utworzeniu maszyny.
                </div>
            </div>
        </div>

        <div class="btn-row">
            <button class="btn btn-primary" type="submit">Zamawiam i tworzę maszynę</button>
            <a class="btn" href="{{ route('panel.dashboard') }}">Anuluj</a>
        </div>
    </form>
@endsection
