{{-- Stan ostatniej instalacji modpacka/loadera/pluginu — z odświeżaniem, dopóki trwa. --}}
@if ($contentJob && ! $contentJob->isFinished())
    <div class="alert alert-info" id="content-job" data-running="1">
        <strong>{{ __('Trwa instalacja') }}:</strong>
        {{ $contentJob->payload['name'] ?? ($contentJob->action === 'loader' ? ucfirst($contentJob->payload['loader'] ?? '').' '.($contentJob->payload['mc'] ?? '') : __('modpack')) }}
        @if ($contentJob->action !== 'addon')
            — <a href="{{ route('panel.apps.show', $app) }}">{{ __('postęp w konsoli') }}</a>
        @endif
    </div>
    @push('scripts')
        <script>setTimeout(() => location.reload(), 4000);</script>
    @endpush
@elseif ($contentJob && $contentJob->status === \App\Models\AppJob::STATUS_FAILED && $contentJob->finished_at?->gt(now()->subHour()))
    <div class="alert alert-error">
        <strong>{{ __('Instalacja nie powiodła się') }}:</strong>
        <pre class="install-error">{{ $contentJob->error }}</pre>
    </div>
@endif
