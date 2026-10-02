{{-- Wiersz tabeli usług (maszyna albo aplikacja): lista usług i pulpit administracji. --}}
    @if ($item instanceof \App\Models\Server)
        <tr>
            <td>
                <span class="os-inline">
                    @if ($item->osFamily())
                        @include('panel.servers._os-badge', ['template' => (object) ['family' => $item->osFamily(), 'name' => $item->osLabel()]])
                    @endif
                    <a href="{{ route('panel.servers.show', $item) }}"><strong>{{ $item->hostname }}</strong></a>
                </span>
                <div class="hint">{{ $item->osLabel() ?? __('system nieznany') }}@if ($item->label) · {{ $item->label }}@endif</div>
            </td>
            <td><span class="pill neutral">{{ $item->virtualization->shortLabel() }}</span></td>
            <td class="muted">{{ $item->user?->email ?? '—' }}</td>
            <td>
                <span class="pill {{ $item->state->tone() }}">{{ $item->state->label() }}</span>
                @if ($item->isSuspended() && $item->suspension_reason)
                    <div class="hint">{{ \Illuminate\Support\Str::limit($item->suspension_reason, 80) }}</div>
                @endif
            </td>
            <td class="mono">{{ $item->primaryIp()?->address ?? '—' }}</td>
            <td class="muted">
                @if ($item->hypervisor)
                    <a href="{{ route('panel.admin.hypervisors.show', $item->hypervisor) }}">{{ $item->hypervisor->name }}</a>
                @else — @endif
            </td>
            <td class="num">{{ __(':vcpu vCPU · :ram GB · :disk GB', ['vcpu' => $item->vcpu, 'ram' => round($item->ram_mb / 1024, 1), 'disk' => $item->disk_gb]) }}</td>
            <td class="muted">{{ $item->created_at->format('d.m.Y') }}</td>
        </tr>
    @else
        <tr>
            <td>
                <a href="{{ route('panel.apps.show', $item) }}"><strong>{{ $item->name }}</strong></a>
                <div class="hint">{{ $item->egg?->displayName() ?? '—' }}</div>
            </td>
            <td><span class="pill info">{{ __('Aplikacja') }}</span></td>
            <td class="muted">{{ $item->user?->email ?? '—' }}</td>
            <td>
                <span class="pill {{ $item->statusTone() }}">{{ $item->statusLabel() }}</span>
                @if ($item->abuse_detected_at) <span class="pill critical plain" title="{{ $item->suspension_reason }}">{{ __('nadużycie') }}</span> @endif
            </td>
            <td class="mono">{{ $item->address() ?? '—' }}</td>
            <td class="muted">
                @if ($item->hypervisor)
                    <a href="{{ route('panel.admin.hypervisors.show', $item->hypervisor) }}">{{ $item->hypervisor->name }}</a>
                @else — @endif
            </td>
            <td class="num">{{ __(':memory MB · :disk MB', ['memory' => $item->memory_mb, 'disk' => $item->disk_mb]) }}</td>
            <td class="muted">{{ $item->created_at->format('d.m.Y') }}</td>
        </tr>
    @endif
