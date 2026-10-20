{{-- Rezygnacja z usługi ze sklepu: wybór terminu albo wycofanie zgłoszonej rezygnacji. --}}
@php
    use App\Domain\Billing\ServiceManager;
    $options = ServiceManager::cancelOptions($service);
    $labels = [
        ServiceManager::CANCEL_NOW => __('Natychmiast'),
        ServiceManager::CANCEL_1D => __('Za 1 dzień'),
        ServiceManager::CANCEL_2D => __('Za 2 dni'),
        ServiceManager::CANCEL_PERIOD_END => __('Z końcem okresu rozliczeniowego (:date)', ['date' => $service->next_due_at?->format('d.m.Y')]),
    ];
    $endsAt = $service->endsAt();
@endphp
@error('service') <div class="alert alert-error">{{ $message }}</div> @enderror
@if ($service->isLive() && $endsAt !== null && $service->renews)
    <p class="muted">{{ __('Rezygnacja zgłoszona. Usługa zostanie usunięta :date razem z danymi.', ['date' => $endsAt->format('d.m.Y H:i')]) }}</p>
    <form method="POST" action="{{ route('panel.billing.service.resume', $service) }}">
        @csrf
        <button class="btn" type="submit">{{ __('Wycofaj rezygnację') }}</button>
    </form>
@elseif (! $service->renews && $service->isLive())
    <p class="muted">{{ __('Usługa jednorazowa. Kończy się :date i zostanie wtedy usunięta razem z danymi.', ['date' => $service->next_due_at?->format('d.m.Y H:i')]) }}</p>
@elseif ($service->status === 'pending' || $service->isLive())
    <form method="POST" action="{{ route('panel.billing.service.cancel', $service) }}"
          data-confirm="{{ __('Na pewno zrezygnować z usługi :name?', ['name' => $service->name]) }}">
        @csrf <input type="hidden" name="confirm" value="1">
        @if ($service->status === 'pending')
            <p class="muted">{{ __('Zamówienie nie zostało opłacone, możesz je anulować.') }}</p>
            <input type="hidden" name="when" value="now">
        @else
            <div class="field">
                <label for="cancel-when-{{ $service->id }}">{{ __('Kiedy usunąć usługę') }}</label>
                <select id="cancel-when-{{ $service->id }}" name="when">
                    @foreach ($options as $option)
                        <option value="{{ $option }}" @selected($option === (in_array(ServiceManager::CANCEL_PERIOD_END, $options, true) ? ServiceManager::CANCEL_PERIOD_END : ServiceManager::CANCEL_NOW))>{{ $labels[$option] }}</option>
                    @endforeach
                </select>
                <div class="hint">
                    {{ $service->metered()
                        ? __('Usługa godzinowa: opłaty są naliczane do chwili usunięcia.')
                        : __('Do wybranego terminu usługa działa normalnie. Potem zostanie usunięta razem z danymi, bez zwrotu za niewykorzystany czas.') }}
                </div>
            </div>
        @endif
        <button class="btn btn-danger" type="submit">{{ $service->status === 'pending' ? __('Anuluj zamówienie') : __('Zrezygnuj z usługi') }}</button>
    </form>
@else
    <p class="muted">{{ __('Usługa jest zakończona.') }}</p>
@endif
