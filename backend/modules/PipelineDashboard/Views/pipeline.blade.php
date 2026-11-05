@extends('pipeline-dashboard::layout')

@section('title', 'Pipeline')

@section('content')
<div class="flex items-center justify-between mb-6">
    <h1 class="text-2xl font-semibold">Pipeline Produkcji</h1>
    <div class="flex items-center gap-2">
        <button id="toggleView" onclick="toggleView()" class="px-3 py-1.5 text-sm bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700" title="Przelacz widok">
            <span id="iconTable" class="hidden">&#9638;</span>
            <span id="iconCards">&#9783;</span>
        </button>
        <a href="{{ route('pipeline.index') }}" class="px-3 py-1.5 text-sm bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700">
            Odswiez
        </a>
    </div>
</div>

@if($orders === [])
<div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-12 text-center text-gray-500 dark:text-gray-400">
    Brak aktywnych zamowien w pipeline
</div>
@else

 {{-- WIDOK TABELI (desktop) --}}
 <div id="viewTable" class="hidden">
    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 overflow-x-auto">
        <table class="w-full text-sm min-w-[640px]">
            <thead>
                <tr class="border-b border-gray-200 dark:border-gray-700 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                    <th class="px-4 py-3 w-16">Woo#</th>
                    <th class="px-4 py-3">Zamowienie</th>
                    <th class="px-4 py-3 w-28">Doli MO</th>
                    <th class="px-4 py-3 w-24">MES WO</th>
                    <th class="px-4 py-3 w-20">Batch</th>
                    <th class="px-4 py-3 w-28">Nesting</th>
                    <th class="px-4 py-3 w-32">Status</th>
                    <th class="px-4 py-3 w-20"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                @foreach ($orders as $order)
                <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50">
                    <td class="px-4 py-3 font-mono text-xs">{{ $order['woo_order_id'] ?? '&mdash;' }}</td>
                    <td class="px-4 py-3 font-medium">
                        <a href="{{ route('pipeline.order', $order['order_no']) }}" class="text-blue-600 dark:text-blue-400 hover:underline font-mono text-xs">
                            {{ $order['order_no'] }}
                        </a>
                    </td>
                    <td class="px-4 py-3">
                        @if($order['doli_mo_completed'])
                            <span class="inline-flex items-center text-green-700 dark:text-green-400 text-xs font-medium">&#10003; Done</span>
                        @elseif($order['doli_mo_ref'])
                            <span class="inline-flex items-center text-yellow-700 dark:text-yellow-400 text-xs font-medium truncate">{{ $order['doli_mo_ref'] }}</span>
                        @else
                            <span class="text-gray-400">&mdash;</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-xs">{{ implode(', ', $order['wo_statuses']) }}</td>
                    <td class="px-4 py-3 text-xs">{{ $order['batch_done'] }}/{{ $order['batch_total'] }}</td>
                    <td class="px-4 py-3 text-xs truncate max-w-[100px]">{{ $order['nesting_status'] ?? '&mdash;' }}</td>
                    <td class="px-4 py-3">
                        @php
                            $stage = $order['current_stage'];
                            if (str_contains($stage, 'done')) {
                                $color = 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200';
                            } elseif (str_contains($stage, 'progress') || str_contains($stage, 'in_progress')) {
                                $color = 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200';
                            } else {
                                $color = 'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-200';
                            }
                        @endphp
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $color }}">{{ $stage }}</span>
                    </td>
                    <td class="px-4 py-3">
                        <a href="{{ route('pipeline.order', $order['order_no']) }}" class="text-xs text-blue-600 dark:text-blue-400 hover:underline">&rarr;</a>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

{{-- WIDOK KART (mobile) --}}
<div id="viewCards" class="hidden">
    <div class="space-y-3">
        @foreach ($orders as $order)
        <a href="{{ route('pipeline.order', $order['order_no']) }}" class="block bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-4 hover:border-gray-400 dark:hover:border-gray-500 transition-colors">
            <div class="flex items-center justify-between mb-2">
                <div class="flex items-center gap-2">
                    <span class="font-mono text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $order['order_no'] }}</span>
                    @php
                        $stage = $order['current_stage'];
                        if (str_contains($stage, 'done')) {
                            $color = 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200';
                        } elseif (str_contains($stage, 'progress') || str_contains($stage, 'in_progress')) {
                            $color = 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200';
                        } else {
                            $color = 'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-200';
                        }
                    @endphp
                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $color }}">{{ $stage }}</span>
                </div>
                <span class="text-xs text-gray-500">&rarr;</span>
            </div>
            <div class="grid grid-cols-2 gap-x-4 gap-y-1 text-xs text-gray-600 dark:text-gray-400">
                @if($order['woo_order_id'])
                <div>Woo: <span class="font-medium text-gray-900 dark:text-gray-100">{{ $order['woo_order_id'] }}</span></div>
                @endif
                <div>WO: <span class="font-medium text-gray-900 dark:text-gray-100">{{ implode(', ', $order['wo_statuses']) }}</span></div>
                <div>Batch: <span class="font-medium text-gray-900 dark:text-gray-100">{{ $order['batch_done'] }}/{{ $order['batch_total'] }}</span></div>
                @if($order['doli_mo_ref'])
                <div>MO: <span class="font-medium text-gray-900 dark:text-gray-100">{{ $order['doli_mo_ref'] }}</span></div>
                @endif
                @if($order['nesting_status'])
                <div>Nesting: <span class="font-medium text-gray-900 dark:text-gray-100">{{ $order['nesting_status'] }}</span></div>
                @endif
            </div>
        </a>
        @endforeach
    </div>
</div>

@endif

<script>
    function toggleView() {
        const isTable = document.getElementById('viewTable').classList.contains('hidden');
        setView(isTable ? 'table' : 'cards');
    }
    function setView(mode) {
        localStorage.setItem('pipeline-view', mode);
        applyView(mode);
    }
    function applyView(mode) {
        const tableEl = document.getElementById('viewTable');
        const cardsEl = document.getElementById('viewCards');
        const iconTable = document.getElementById('iconTable');
        const iconCards = document.getElementById('iconCards');
        if (!tableEl || !cardsEl) return;
        if (mode === 'table') {
            tableEl.classList.remove('hidden');
            cardsEl.classList.add('hidden');
            iconTable.classList.add('hidden');
            iconCards.classList.remove('hidden');
        } else {
            cardsEl.classList.remove('hidden');
            tableEl.classList.add('hidden');
            iconTable.classList.remove('hidden');
            iconCards.classList.add('hidden');
        }
    }
    (function() {
        const stored = localStorage.getItem('pipeline-view');
        if (stored) {
            applyView(stored);
        } else {
            applyView(window.innerWidth >= 768 ? 'table' : 'cards');
        }
    })();
</script>
@endsection
