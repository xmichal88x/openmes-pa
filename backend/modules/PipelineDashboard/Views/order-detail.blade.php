@extends('pipeline-dashboard::layout', ['backUrl' => route('pipeline.index'), 'backLabel' => 'Pipeline'])

@section('title', "Zamowienie {$orderNo}")

@section('content')
<div class="mb-6">
    <h1 class="text-2xl font-semibold">{{ $orderNo }}</h1>
    @if($workOrders->count())
        @php
            $allDone = $workOrders->every('status', 'DONE');
            $anyProgress = $workOrders->contains('status', 'IN_PROGRESS');
            if ($allDone) {
                $pipelineColor = 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200';
                $pipelineLabel = 'ZAKONCZONE';
            } elseif ($anyProgress) {
                $pipelineColor = 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200';
                $pipelineLabel = 'W PROGRESJE';
            } else {
                $pipelineColor = 'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-200';
                $pipelineLabel = 'OCZEKUJE';
            }
        @endphp
        <span class="inline-flex items-center px-2.5 py-1 mt-2 rounded text-xs font-semibold {{ $pipelineColor }}">{{ $pipelineLabel }}</span>
    @endif
</div>

@foreach ($workOrders as $wo)
<div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 mb-4">
    <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
        <div class="flex items-center gap-2">
            <span class="font-mono text-sm font-semibold">{{ $wo->order_no }}</span>
            @php
                $statusColor = match($wo->status) {
                    'DONE' => 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
                    'IN_PROGRESS' => 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200',
                    'PENDING' => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200',
                    'BLOCKED' => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
                    default => 'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-200',
                };
            @endphp
            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $statusColor }}">{{ $wo->status }}</span>
        </div>
    </div>

    <div class="px-4 py-3">
        <div class="flex items-center justify-between text-xs text-gray-500 dark:text-gray-400 mb-2">
            <span>Postep produkcji</span>
            <span class="font-medium text-gray-900 dark:text-gray-100">{{ $wo->produced_qty ?? 0 }} / {{ $wo->planned_qty }}</span>
        </div>
        @php
            $planned = (float) ($wo->planned_qty ?? 1);
            $produced = (float) ($wo->produced_qty ?? 0);
            $pct = $planned > 0 ? min(100, round(($produced / $planned) * 100)) : 0;
        @endphp
        <div class="w-full h-2 bg-gray-200 dark:bg-gray-700 rounded-full overflow-hidden">
            <div class="h-full rounded-full {{ $pct >= 100 ? 'bg-green-500' : 'bg-blue-500' }}" style="width: {{ $pct }}%"></div>
        </div>
    </div>

    @if($wo->batches->count())
    <div class="px-4 py-2 border-t border-gray-100 dark:border-gray-800 bg-gray-50 dark:bg-gray-800/50 rounded-b-xl">
        <div class="text-xs font-medium text-gray-500 dark:text-gray-400 mb-2">Batches</div>
        @foreach ($wo->batches as $batch)
        <div class="flex items-center justify-between py-1.5 {{ !$loop->last ? 'border-b border-gray-100 dark:border-gray-800' : '' }}">
            <div class="flex items-center gap-2">
                <span class="font-mono text-xs text-gray-700 dark:text-gray-300">#{{ $batch->id }}</span>
                @php
                    $bStatusColor = match($batch->status) {
                        'DONE' => 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
                        'IN_PROGRESS' => 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200',
                        default => 'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-200',
                    };
                @endphp
                <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium {{ $bStatusColor }}">{{ $batch->status }}</span>
            </div>
            @if($batch->steps->count())
            <span class="text-[10px] text-gray-500 dark:text-gray-400">
                {{ $batch->steps->where('status', 'DONE')->count() }}/{{ $batch->steps->count() }} krokow
            </span>
            @endif
        </div>
        @endforeach
    </div>
    @endif
</div>
@endforeach

<div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700">
    <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700">
        <h2 class="font-semibold text-sm">Historia pipeline</h2>
    </div>
    <div class="divide-y divide-gray-100 dark:divide-gray-800">
        @forelse ($logs as $log)
        <div class="px-4 py-2 flex items-center gap-3 text-xs">
            <span class="text-gray-500 w-28 shrink-0">{{ $log->logged_at?->format('d.m H:i') }}</span>
            @php
                $logColor = str_contains($log->stage, 'done')
                    ? 'bg-green-100 text-green-800'
                    : 'bg-blue-100 text-blue-800';
            @endphp
            <span class="inline-flex items-center px-2 py-0.5 rounded font-medium {{ $logColor }}">{{ $log->stage }}</span>
            <span class="text-gray-400 truncate">{{ $log->source }}</span>
        </div>
        @empty
        <div class="px-4 py-8 text-center text-gray-400 text-sm">Brak historii</div>
        @endforelse
    </div>
</div>
@endsection
