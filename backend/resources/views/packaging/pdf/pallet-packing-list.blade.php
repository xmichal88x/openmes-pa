<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>{{ __('Packing list') }} {{ $list['pallet']['pallet_no'] }}</title>
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
        .box { margin-top: 4mm; }
        .box-title { font-weight: bold; }
        .group { margin-top: 5mm; }
        .foot { position: fixed; bottom: -8mm; left: 0; right: 0; font-size: 7.5pt; color: #888; }
        .sig { width: 100%; margin-top: 10mm; border-collapse: collapse; }
        .sig td { width: 33%; padding-top: 10mm; border-top: 1px solid #999; text-align: center; font-size: 8pt; color: #666; }
    </style>
</head>
<body>
@php($p = $list['pallet'])
<table class="head">
    <tr>
        <td>
            <h1>{{ __('Packing list') }} · <span class="mono">{{ $p['pallet_no'] }}</span></h1>
            <div class="muted">{{ __('Generated :at', ['at' => $generatedAt]) }}</div>
            <table class="facts" style="margin-top:4mm">
                <tr>
                    <td class="k">{{ __('Work order') }}</td>
                    <td class="mono">{{ $p['order_no'] ?? '—' }}</td>
                    <td class="k">{{ __('Customer order') }}</td>
                    <td class="mono">{{ $p['customer_order_no'] ?? '—' }}</td>
                </tr>
                <tr>
                    <td class="k">{{ __('Product') }}</td>
                    <td>{{ $p['product'] ?? '—' }}@if($p['product_code']) <span class="muted mono">{{ $p['product_code'] }}</span>@endif</td>
                    <td class="k">{{ __('Kind') }}</td>
                    <td>{{ __(ucfirst($p['kind'])) }} · {{ __(ucfirst($p['status'])) }}</td>
                </tr>
                <tr>
                    <td class="k">{{ __('Quality') }}</td>
                    <td>{{ __(ucfirst($p['quality_status'] ?? 'pending')) }}</td>
                    <td class="k">{{ __('Destination') }}</td>
                    <td>{{ $p['destination'] ?? '—' }}</td>
                </tr>
                <tr>
                    <td class="k">{{ __('Created') }}</td>
                    <td>{{ $p['created_at'] ?? '—' }}</td>
                    <td class="k">{{ __('Shipped') }}</td>
                    <td>{{ $p['shipped_at'] ?? '—' }}</td>
                </tr>
            </table>
        </td>
        <td class="qr" style="width:30mm">
            <img src="{{ $qrPng }}" alt="{{ $p['pallet_no'] }}">
            <div class="mono" style="font-size:8pt">{{ $p['pallet_no'] }}</div>
        </td>
    </tr>
</table>

<div class="totals">
    <b>{{ $list['totals']['parts'] }}</b> {{ __('pcs') }} &nbsp;·&nbsp;
    <b>{{ $list['totals']['boxes'] }}</b> {{ __('boxes') }} &nbsp;·&nbsp;
    <b>{{ $list['totals']['weight_kg'] }}</b> kg
    @if($p['qty'] !== $list['totals']['parts']) &nbsp;·&nbsp; <span class="muted">{{ __('pallet count') }}: {{ $p['qty'] }}</span>@endif
</div>

@forelse($list['boxes'] as $box)
    <div class="box">
        <h3 class="box-title mono">{{ $box['box_id'] }}</h3>
        <div class="muted" style="margin-bottom:1.5mm">
            {{ $box['part_count'] }} {{ __('pcs') }}
            @if($box['weight_kg'] !== null) · {{ $box['weight_kg'] }} kg @endif
            · {{ __(ucfirst($box['status'])) }}
            @if($box['closed_at']) · {{ $box['closed_at'] }} @endif
        </div>
        @include('packaging.pdf._work-order-parts', ['parts' => $box['parts']])
    </div>
@empty
@endforelse

@if($list['loose_parts'] !== [])
    <div class="group">
        <h2>{{ __('Loose parts on the pallet') }}</h2>
        @include('packaging.pdf._work-order-parts', ['parts' => $list['loose_parts']])
    </div>
@endif

<table class="sig">
    <tr><td>{{ __('Packed by') }}</td><td>{{ __('Checked by') }}</td><td>{{ __('Received by') }}</td></tr>
</table>
<div class="foot">{{ __('Packing list') }} {{ $p['pallet_no'] }} · OpenMES</div>
</body>
</html>
