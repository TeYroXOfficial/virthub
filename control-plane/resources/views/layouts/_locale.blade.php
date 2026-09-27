{{-- Przełącznik języka: zapisuje wybór w sesji i na koncie. --}}
<form method="POST" action="{{ route('locale.switch') }}" class="locale-switch" aria-label="{{ __('Język') }}">
    @csrf
    @foreach (config('virthub.locales') as $code => $name)
        <button type="submit" name="locale" value="{{ $code }}" title="{{ $name }}"
                @if (app()->getLocale() === $code) aria-current="true" @endif>{{ strtoupper($code) }}</button>
    @endforeach
</form>
