{{--
    Wykres słupkowy jednej serii (bez legendy — tytuł karty nazywa serię).
    $points: list<['label' => tekst osi, 'tip' => opis w podpowiedzi, 'value' => liczba]>
    $format: fn(int) => tekst wartości;  $title: opis dla czytników ekranu
--}}
@php
    $w = 600; $h = 160; $pad = 22; $plotH = $h - $pad;
    $n = max(1, count($points));
    $max = max(1, max(array_column($points, 'value') ?: [0]));
    $slot = $w / $n; $bar = max(2, $slot - 2); // 2 px odstępu między słupkami
    $ticks = $n > 1 ? array_unique([0, intdiv($n - 1, 2), $n - 1]) : [0];
    $id = 'chart-'.\Illuminate\Support\Str::random(6);
@endphp
<figure class="bar-chart" data-bar-chart>
    <svg viewBox="0 0 {{ $w }} {{ $h }}" preserveAspectRatio="none" role="img" aria-labelledby="{{ $id }}-t">
        <title id="{{ $id }}-t">{{ $title }}</title>
        <line x1="0" x2="{{ $w }}" y1="{{ $plotH * 0.5 }}" y2="{{ $plotH * 0.5 }}" class="bar-chart-grid"/>
        <line x1="0" x2="{{ $w }}" y1="0.5" y2="0.5" class="bar-chart-grid"/>
        @foreach ($points as $i => $p)
            @php $bh = $p['value'] > 0 ? max(3, $p['value'] / $max * ($plotH - 4)) : 0; $x = $i * $slot + 1; @endphp
            {{-- obszar trafienia szerszy niż słupek --}}
            <rect class="bar-chart-hit" x="{{ $i * $slot }}" y="0" width="{{ $slot }}" height="{{ $plotH }}" data-tip="{{ $p['tip'] }}" data-value="{{ $format($p['value']) }}"/>
            @if ($bh > 0)
                <path class="bar-chart-bar" d="M{{ $x }},{{ $plotH }} V{{ $plotH - $bh + 4 }} q0,-4 4,-4 H{{ $x + $bar - 4 }} q4,0 4,4 V{{ $plotH }} Z"/>
            @endif
        @endforeach
        <line x1="0" x2="{{ $w }}" y1="{{ $plotH }}" y2="{{ $plotH }}" class="bar-chart-axis"/>
    </svg>
    <div class="bar-chart-labels">
        @foreach ($ticks as $t)
            <span style="left: {{ ($t + 0.5) / $n * 100 }}%">{{ $points[$t]['label'] ?? '' }}</span>
        @endforeach
    </div>
    <div class="bar-chart-max">{{ $format($max) }}</div>
    <div class="bar-chart-tip" hidden></div>
    <details class="bar-chart-table">
        <summary>{{ __('Pokaż dane w tabeli') }}</summary>
        <table><tbody>
            @foreach ($points as $p) <tr><td>{{ $p['tip'] }}</td><td class="num">{{ $format($p['value']) }}</td></tr> @endforeach
        </tbody></table>
    </details>
</figure>
@once
    @push('scripts')
        <script>
            document.querySelectorAll('[data-bar-chart]').forEach(function (fig) {
                var tip = fig.querySelector('.bar-chart-tip');
                fig.querySelectorAll('.bar-chart-hit').forEach(function (hit) {
                    hit.addEventListener('mouseenter', function () {
                        tip.innerHTML = '';
                        var a = document.createElement('strong'); a.textContent = hit.dataset.value;
                        var b = document.createElement('span'); b.textContent = hit.dataset.tip;
                        tip.append(a, b); tip.hidden = false;
                        var box = fig.getBoundingClientRect(), r = hit.getBoundingClientRect();
                        var left = Math.min(Math.max(r.left + r.width / 2 - box.left, 60), box.width - 60);
                        tip.style.left = left + 'px';
                        fig.querySelectorAll('.is-hover').forEach(function (e) { e.classList.remove('is-hover'); });
                        hit.classList.add('is-hover');
                    });
                });
                fig.addEventListener('mouseleave', function () {
                    tip.hidden = true;
                    fig.querySelectorAll('.is-hover').forEach(function (e) { e.classList.remove('is-hover'); });
                });
            });
        </script>
    @endpush
@endonce
