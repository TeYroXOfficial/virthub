{{-- Trwające pobieranie na węźle: pasek + opis, odświeżane przez js/downloads.js. --}}
<div class="dl" data-download="{{ $kind }}-{{ $download->id }}">
    <div class="dl-head">
        <span class="dl-node">{{ $download->hypervisor->name }}</span>
        <span class="dl-pct" data-dl-pct>{{ $download->progress !== null ? $download->progress.'%' : ($download->status === 'queued' ? 'w kolejce' : 'start…') }}</span>
    </div>
    <div class="dl-bar @if($download->progress === null) is-indeterminate @endif"><i data-dl-bar style="width: {{ (int) $download->progress }}%"></i></div>
    <div class="hint dl-detail" data-dl-detail>{{ $download->progress_detail }}</div>
</div>
