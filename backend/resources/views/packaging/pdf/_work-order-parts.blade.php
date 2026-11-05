@php($statusLabel = fn (string $s) => match ($s) {
    'scanned' => __('Scanned'),
    'expected' => __('Expected'),
    'void' => __('Void'),
    default => $s,
})
<table class="units">
    <thead>
        <tr>
            <th style="width:8mm">#</th>
            <th>{{ __('Label ID') }}</th>
            <th style="width:12mm">{{ __('Seq.') }}</th>
            <th>{{ __('Part') }}</th>
            <th>{{ __('Material') }}</th>
            <th>{{ __('Sheet') }}</th>
            <th>{{ __('Dimensions') }}</th>
            <th style="width:18mm">{{ __('Scan') }}</th>
            <th style="width:16mm">{{ __('Weight') }}</th>
        </tr>
    </thead>
    <tbody>
    @forelse($parts as $i => $part)
        <tr>
            <td class="muted">{{ $i + 1 }}</td>
            <td class="mono">{{ $part['label_id'] }}</td>
            <td class="muted">{{ $part['part_seq'] ?? '—' }}</td>
            <td>{{ $part['part_name'] ?? '—' }}</td>
            <td>{{ $part['material'] ?? '—' }}</td>
            <td>{{ $part['sheet_name'] ?? '—' }}</td>
            <td>{{ $part['dimensions'] ?? '—' }}</td>
            <td>{{ $statusLabel($part['state']) }}</td>
            <td>{{ $part['weight_kg'] !== null ? $part['weight_kg'].' kg' : '—' }}</td>
        </tr>
    @empty
        <tr><td colspan="9" class="muted">{{ __('No parts recorded.') }}</td></tr>
    @endforelse
    </tbody>
</table>
