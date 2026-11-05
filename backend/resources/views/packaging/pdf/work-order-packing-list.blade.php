<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>{{ __('Packing list') }} {{ $list['work_order']['order_no'] }}</title>
    <style>
        @page { margin: 14mm 14mm 16mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9.5pt; color: #111; margin: 0; }
        h1 { font-size: 18pt; margin: 0 0 2mm; letter-spacing: -0.3pt; }
        h2 { font-size: 12pt; margin: 6mm 0 2mm; }
        h3 { font-size: 10pt; margin: 4mm 0 1.5mm; }
        .muted { color: #666; }
        .mono { font-family: DejaVu Sans Mono, monospace; }
        .head { width: 100%; border-collapse: collapse; margin-bottom: 6mm; }
        .head td { vertical-align: top; padding: 0; }
        .facts { width: 100%; border-collapse: collapse; }
        .facts td { padding: 1mm 4mm 1mm 0; vertical-align: top; }
        .facts .k { color: #666; font-size: 8pt; text-transform: uppercase; letter-spacing: 0.5pt; width: 32mm; }
        .qr { text-align: right; }
        .qr img { width: 26mm; height: 26mm; }
        .totals { margin: 0 0 5mm; padding: 3mm 4mm; background: #f3f1ec; border-radius: 2mm; }
        .totals b { font-size: 12pt; }
        table.units { width: 100%; border-collapse: collapse; page-break-inside: auto; }
        table.units th { text-align: left; font-size: 7.5pt; text-transform: uppercase; letter-spacing: 0.5pt; color: #666; border-bottom: 1px solid #999; padding: 1.5mm 2mm; }
        table.units td { padding: 1.4mm 2mm; border-bottom: 1px solid #e3e0d8; vertical-align: top; }
        table.units tr { page-break-inside: avoid; }
        .pallet { margin-top: 5mm; }
        .pallet-head { padding: 2mm 3mm; background: #f3f1ec; border-radius: 1.5mm; }
        .pallet-head .no { font-size: 11pt; font-weight: bold; }
        .box-title { font-weight: bold; }
        .group { margin-top: 5mm; }
        .foot { position: fixed; bottom: -8mm; left: 0; right: 0; font-size: 7.5pt; color: #888; }
        .sig { width: 100%; margin-top: 10mm; border-collapse: collapse; }
        .sig td { width: 33%; padding-top: 10mm; border-top: 1px solid #999; text-align: center; font-size: 8pt; color: #666; }
    </style>
</head>
<body>
@php($w = $list['work_order'])
@php($statusLabel = fn (string $s) => match ($s) {
    'scanned' => __('Scanned'),
    'expected' => __('Expected'),
    'void' => __('Void'),
    default => $s,
})
<table class="head">
    <tr>
        <td>
            <h1>{{ __('Packing list') }} · <span class="mono">{{ $w['order_no'] }}</span></h1>
            <div class="muted">{{ __('Generated :at', ['at' => $generatedAt]) }}</div>
            <table class="facts" style="margin-top:4mm">
                <tr>
                    <td class="k">{{ __('Customer order') }}</td>
                    <td class="mono">{{ $w['customer_order_no'] ?? '—' }}</td>
                    <td class="k">{{ __('Customer') }}</td>
                    <td>{{ $w['customer'] ?? '—' }}</td>
                </tr>
                <tr>
                    <td class="k">{{ __('Product') }}</td>
                    <td>{{ $w['product'] ?? '—' }}@if($w['product_code']) <span class="muted mono">{{ $w['product_code'] }}</span>@endif</td>
                    <td class="k">{{ __('Status') }}</td>
                    <td>{{ __(ucfirst(strtolower($w['status']))) }}</td>
                </tr>
                <tr>
                    <td class="k">{{ __('Created') }}</td>
                    <td>{{ $w['created_at'] ?? '—' }}</td>
                    <td class="k">{{ __('Due date') }}</td>
                    <td>{{ $w['due_date'] ?? '—' }}</td>
                </tr>
            </table>
        </td>
        <td class="qr" style="width:30mm">
            <img src="{{ $qrPng }}" alt="{{ $w['order_no'] }}">
            <div class="mono" style="font-size:8pt">{{ $w['order_no'] }}</div>
        </td>
    </tr>
</table>

<div class="totals">
    <b>{{ $list['totals']['parts'] }}</b> {{ __('pcs') }} &nbsp;·&nbsp;
    <b>{{ $list['totals']['boxes'] }}</b> {{ __('boxes') }} &nbsp;·&nbsp;
    <b>{{ $list['totals']['pallets'] }}</b> {{ __('pallets') }} &nbsp;·&nbsp;
    <b>{{ $list['totals']['weight_kg'] }}</b> kg
</div>

@foreach($list['pallets'] as $pallet)
    <div class="pallet">
        <div class="pallet-head">
            <span class="no mono">{{ $pallet['pallet_no'] }}</span>
            <span class="muted">· {{ __(ucfirst($pallet['kind'])) }} · {{ __(ucfirst($pallet['status'])) }} · {{ $pallet['part_count'] }} {{ __('pcs') }} · {{ $pallet['box_count'] }} {{ __('boxes') }}</span>
        </div>

        @foreach($pallet['boxes'] as $box)
            <h3 class="box-title mono">{{ $box['box_id'] }}</h3>
            <div class="muted" style="margin-bottom:1.5mm">
                {{ $box['part_count'] }} {{ __('pcs') }}
                @if($box['weight_kg'] !== null) · {{ $box['weight_kg'] }} kg @endif
                · {{ __(ucfirst($box['status'])) }}
                @if($box['closed_at']) · {{ $box['closed_at'] }} @endif
            </div>
            @include('packaging.pdf._work-order-parts', ['parts' => $box['parts']])
        @endforeach

        @if($pallet['loose_parts'] !== [])
            <h3>{{ __('Loose parts on the pallet') }}</h3>
            @include('packaging.pdf._work-order-parts', ['parts' => $pallet['loose_parts']])
        @endif
    </div>
@endforeach

@if($list['unassigned_boxes'] !== [])
    <div class="group">
        <h2>{{ __('Boxes not on a pallet') }}</h2>
        @foreach($list['unassigned_boxes'] as $box)
            <h3 class="box-title mono">{{ $box['box_id'] }}</h3>
            <div class="muted" style="margin-bottom:1.5mm">
                {{ $box['part_count'] }} {{ __('pcs') }}
                @if($box['weight_kg'] !== null) · {{ $box['weight_kg'] }} kg @endif
                · {{ __(ucfirst($box['status'])) }}
                @if($box['closed_at']) · {{ $box['closed_at'] }} @endif
            </div>
            @include('packaging.pdf._work-order-parts', ['parts' => $box['parts']])
        @endforeach
    </div>
@endif

@if($list['unassigned_parts'] !== [])
    <div class="group">
        <h2>{{ __('Parts not assigned to a box or pallet') }}</h2>
        @include('packaging.pdf._work-order-parts', ['parts' => $list['unassigned_parts']])
    </div>
@endif

<table class="sig">
    <tr><td>{{ __('Packed by') }}</td><td>{{ __('Checked by') }}</td><td>{{ __('Received by') }}</td></tr>
</table>
<div class="foot">{{ __('Packing list') }} {{ $w['order_no'] }} · OpenMES</div>
</body>
</html>
