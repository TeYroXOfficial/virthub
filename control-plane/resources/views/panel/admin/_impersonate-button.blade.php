@if (\App\Domain\Access\Impersonation::allowed(auth()->user(), $target))
    <form method="POST" action="{{ route('panel.admin.users.impersonate', $target) }}" style="margin:0"
          data-confirm="{{ __('Zalogować się jako :email? Zobaczysz panel tak jak klient; wrócisz przyciskiem na górze strony.', ['email' => $target->email]) }}">
        @csrf
        <button class="btn" type="submit"><x-icon name="logout" :size="15"/> {{ __('Zaloguj jako') }}</button>
    </form>
@endif
