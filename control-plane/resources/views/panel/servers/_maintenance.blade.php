{{-- Reinstalacja systemu i płyta ISO. --}}
@php
    $canRebuild = auth()->user()->can('rebuild', $server);
    $canIso = ! $server->isContainer() && auth()->user()->can('iso', $server);
@endphp

@if ($canRebuild || $canIso)
    <div class="grid grid-2" style="margin-top:16px">
        @if ($canRebuild)
            <div class="card" id="reinstall">
                <h3 class="card-title"><x-icon name="refresh" :size="16"/> Reinstalacja systemu</h3>
                @if ($templates->isEmpty())
                    <p class="muted">Brak systemów dostępnych dla tego typu maszyny.</p>
                @elseif (! $server->acceptsCommands())
                    <p class="muted">Poczekaj, aż skończy się bieżąca operacja.</p>
                @else
                    <form method="POST" action="{{ route('panel.servers.rebuild', $server) }}"
                          onsubmit="return confirm('Wszystkie dane na dysku maszyny zostaną bezpowrotnie usunięte. Kontynuować?')">
                        @csrf
                        <div class="field">
                            <label for="rb-template">Nowy system</label>
                            <select id="rb-template" name="template" required>
                                @foreach ($templates as $t)
                                    <option value="{{ $t->id }}" @selected((int) old('template', $server->os_template_id) === $t->id)>
                                        {{ $t->name }}{{ $t->id === $server->os_template_id ? ' (obecny)' : '' }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="field">
                            <label for="rb-key">Klucz publiczny SSH <span class="muted">(opcjonalnie)</span></label>
                            <textarea id="rb-key" name="ssh_key" rows="2" placeholder="ssh-ed25519 AAAA… twoj@komputer">{{ old('ssh_key') }}</textarea>
                            <div class="hint">Hasło roota wygenerujemy zawsze i pokażemy tutaj po reinstalacji.</div>
                        </div>
                        <div class="field">
                            <label for="rb-confirm">Wpisz <code>{{ $server->hostname }}</code>, aby potwierdzić</label>
                            <input id="rb-confirm" name="confirm_hostname" type="text" autocomplete="off" required>
                        </div>
                        <button class="btn btn-danger" type="submit">Reinstaluj system</button>
                        <p class="hint" style="margin-top:10px">
                            Adresy IP, zapora i parametry maszyny zostają. Dysk zostanie wyczyszczony —
                            zrób wcześniej kopię, jeśli czegoś potrzebujesz.
                        </p>
                    </form>
                @endif
            </div>
        @endif

        @if ($canIso)
            <div class="card" id="iso">
                <h3 class="card-title"><x-icon name="disc" :size="16"/> Obraz ISO</h3>
                <dl class="kv" style="margin-bottom:14px">
                    <dt>W napędzie</dt>
                    <dd>{{ $server->iso?->name ?? 'brak płyty' }}</dd>
                    <dt>Rozruch</dt>
                    <dd>
                        @if ($server->boot_from_iso && $server->iso)
                            <span class="pill warning">z płyty</span>
                        @else
                            <span class="pill neutral">z dysku</span>
                        @endif
                    </dd>
                </dl>
                @if (! $server->acceptsCommands())
                    <p class="muted">Poczekaj, aż skończy się bieżąca operacja.</p>
                @else
                    <form method="POST" action="{{ route('panel.servers.iso', $server) }}">
                        @csrf
                        <div class="field">
                            <label for="iso-select">Płyta</label>
                            <select id="iso-select" name="iso">
                                <option value="">— wysuń płytę —</option>
                                @foreach ($isos as $iso)
                                    <option value="{{ $iso->id }}" @selected($server->iso_image_id === $iso->id)>
                                        {{ $iso->name }}@unless ($iso->is_public) (personel)@endunless
                                    </option>
                                @endforeach
                            </select>
                            @if ($isos->isEmpty())
                                <div class="hint">Na węźle tej maszyny nie ma jeszcze żadnych obrazów.</div>
                            @endif
                        </div>
                        <div class="field">
                            <label style="font-weight:400; display:flex; gap:8px; align-items:center;">
                                <input type="checkbox" name="boot" value="1" style="width:auto" @checked($server->boot_from_iso)>
                                Uruchamiaj z płyty (przed dyskiem)
                            </label>
                            <label style="font-weight:400; display:flex; gap:8px; align-items:center;">
                                <input type="checkbox" name="restart" value="1" style="width:auto">
                                Zastosuj od razu — wyłącz i włącz maszynę
                            </label>
                        </div>
                        <button class="btn btn-primary" type="submit">Zapisz</button>
                        <p class="hint" style="margin-top:10px">
                            Instalację z płyty prowadzisz w konsoli. Po instalacji wysuń płytę albo wyłącz rozruch z niej,
                            żeby maszyna startowała z dysku. Adresy IP maszyny widzisz powyżej — ustaw je w instalatorze.
                        </p>
                    </form>
                @endif
            </div>
        @endif
    </div>
@endif
