{{-- Zużycie na żywo (wypełnia app-console.js) — na przeglądzie i nad konsolą. --}}
<div class="app-stats">
    <div class="card stat"><div class="stat-label">{{ __('Stan') }}</div><div class="stat-value" data-stat="state">—</div></div>
    <div class="card stat"><div class="stat-label">{{ __('Procesor') }}</div><div class="stat-value" data-stat="cpu">—</div>
        <div class="hint">{{ $app->cpu_percent ? __('limit :percent%', ['percent' => $app->cpu_percent]) : __('bez limitu') }}</div></div>
    <div class="card stat"><div class="stat-label">{{ __('Pamięć') }}</div><div class="stat-value" data-stat="memory">—</div>
        <div class="hint">{{ __('z :mb MB', ['mb' => $app->memory_mb]) }}</div></div>
    <div class="card stat"><div class="stat-label">{{ __('Dysk') }}</div><div class="stat-value" data-stat="disk">—</div>
        <div class="hint">{{ __('z :mb MB', ['mb' => $app->disk_mb]) }}</div></div>
</div>
