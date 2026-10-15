{{-- Udostępnianie maszyny innym kontom (właściciel) i zmiana właściciela (administrator). --}}
@php
    $myShare = $server->shareFor(auth()->user());
    $canShare = auth()->user()->can('share', $server);
    $canTransfer = auth()->user()->can('transfer', $server);
@endphp
@if ($myShare || $canShare || $canTransfer)
    <div class="card" id="sharing">
        <h3 class="card-title"><x-icon name="users" :size="16"/> {{ __('Udostępnianie') }}</h3>
        @error('sharing') <div class="alert alert-error"><div>{{ $message }}</div></div> @enderror

        @if ($myShare)
            <p class="muted">{{ __('Maszyna należy do :email i jest Ci udostępniona. Możesz: :list.', ['email' => $server->user?->email ?? '—', 'list' => collect($myShare->permissions)->map(fn ($p) => \App\Models\ServerShare::label($p))->join(', ') ?: __('tylko podgląd')]) }}</p>
            <form method="POST" action="{{ route('panel.servers.shares.destroy', [$server, $myShare]) }}" data-confirm="{{ __('Zrezygnować z dostępu do tej maszyny?') }}">
                @csrf @method('DELETE') <button class="btn btn-sm" type="submit">{{ __('Zrezygnuj z dostępu') }}</button>
            </form>
        @endif

        @if ($canShare)
            <p class="muted">{{ __('Inna osoba z kontem w panelu zobaczy maszynę na swojej liście i może tylko to, co jej nadasz. Usunąć maszyny, udostępnić jej dalej ani przejąć nie może.') }}</p>
            @php $shares = $server->shares()->with('user:id,email,name')->get(); @endphp
            @if ($shares->isNotEmpty())
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>{{ __('Osoba') }}</th><th>{{ __('Uprawnienia') }}</th><th></th></tr></thead>
                        <tbody>
                        @foreach ($shares as $share)
                            <tr>
                                <td>{{ $share->user?->email }}</td>
                                <td>{{ collect($share->permissions)->map(fn ($p) => \App\Models\ServerShare::label($p))->join(', ') ?: __('tylko podgląd') }}</td>
                                <td style="text-align:right">
                                    <form method="POST" action="{{ route('panel.servers.shares.destroy', [$server, $share]) }}" style="margin:0" data-confirm="{{ __('Cofnąć dostęp :email?', ['email' => $share->user?->email]) }}">
                                        @csrf @method('DELETE') <button class="btn btn-sm btn-danger" type="submit">{{ __('Cofnij') }}</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
            <form method="POST" action="{{ route('panel.servers.shares.store', $server) }}" style="margin-top:12px">
                @csrf
                <div class="field" style="max-width:420px"><label for="share-email">{{ __('E-mail konta') }}</label>
                    <input id="share-email" name="email" type="email" required maxlength="255" placeholder="kolega@example.com"></div>
                <fieldset class="field" style="border:0; padding:0; margin:0 0 10px">
                    <legend style="font-weight:600; margin-bottom:6px">{{ __('Uprawnienia') }}</legend>
                    <div class="check-columns">
                        @foreach (\App\Models\ServerShare::PERMISSIONS as $perm)
                            <label class="check-line"><input type="checkbox" name="permissions[]" value="{{ $perm }}" @checked(in_array($perm, ['servers.power', 'servers.console'], true))> {{ \App\Models\ServerShare::label($perm) }}</label>
                        @endforeach
                    </div>
                    <div class="hint">{{ __('Ponowne udostępnienie tej samej osobie zmienia jej uprawnienia.') }}</div>
                </fieldset>
                <button class="btn" type="submit">{{ __('Udostępnij') }}</button>
            </form>
        @endif

        @if ($canTransfer)
            <form method="POST" action="{{ route('panel.servers.owner', $server) }}" style="margin-top:16px; padding-top:12px; border-top:1px solid var(--border)"
                  data-confirm="{{ __('Przenieść maszynę na inne konto? Obecny właściciel straci do niej dostęp.') }}">
                @csrf
                <h4 style="margin:0 0 8px">{{ __('Zmiana właściciela') }} <span class="pill neutral">{{ __('administrator') }}</span></h4>
                <div class="btn-row" style="align-items:flex-end">
                    <div class="field" style="margin:0; min-width:260px; flex:1"><label for="owner-email">{{ __('E-mail nowego właściciela') }}</label>
                        <input id="owner-email" name="email" type="email" required maxlength="255" placeholder="{{ $server->user?->email }}"></div>
                    <label class="check-line" style="margin:0 0 8px"><input type="checkbox" name="with_billing" value="1"> {{ __('Przenieś też usługę w billingu (kolejne faktury)') }}</label>
                    <button class="btn" type="submit">{{ __('Przenieś') }}</button>
                </div>
            </form>
        @endif
    </div>
@endif
