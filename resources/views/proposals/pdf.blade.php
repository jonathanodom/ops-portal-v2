<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 36px; }
        body { color: #0f172a; font-family: DejaVu Sans, sans-serif; font-size: 11px; }
        h1, h2, h3 { margin: 0 0 8px; }
        section { margin-top: 22px; }
        table { width: 100%; margin-top: 12px; border-collapse: collapse; }
        th, td { padding: 8px; border-bottom: 1px solid #cbd5e1; text-align: left; }
        .right { text-align: right; }
        .muted { color: #475569; }
        .total { font-size: 15px; font-weight: bold; }
        .page { page-break-before: always; }
    </style>
</head>
<body>
@php
    $snapshot = $publication->snapshot;
    $presentation = $snapshot['publication'];
@endphp
<header>
    <img src="{{ $logoDataUri }}" alt="" style="max-height:60px;max-width:220px">
    <div style="float:right;text-align:right"><strong>PROPOSAL</strong><h1>{{ $snapshot['document']['number'] }}</h1></div>
</header>
<div style="clear:both"></div>
@foreach($snapshot['template']['sections'] as $templateSection)
    @continue(! $templateSection['customer_visible'])
    @switch($templateSection['section_type'])
        @case('cover')
            <section>
                <h1>{{ $snapshot['document']['title'] }}</h1>
                <p class="muted">Prepared for {{ $snapshot['customer']['display_name'] }}@if($snapshot['customer']['site_name']) · {{ $snapshot['customer']['site_name'] }}@endif</p>
            </section>
            @break
        @case('scope')
            <section>
                <h2>{{ $templateSection['heading'] }}</h2>
                @forelse($snapshot['sections'] as $section)
                    <h3>{{ $section['heading'] }}</h3>
                    <p style="white-space:pre-line">{{ $section['body'] }}</p>
                @empty
                    <p class="muted">Scope will be completed before customer delivery.</p>
                @endforelse
            </section>
            @break
        @case('pricing')
            <section>
                <h2>{{ $templateSection['heading'] }}</h2>
                <table>
                    <thead><tr><th>Scope</th><th>Quantity</th>@if($presentation['show_line_details'])<th class="right">Amount</th>@endif</tr></thead>
                    <tbody>
                    @foreach($snapshot['lines'] as $line)
                        @if(! $line['optional'] || $line['included'])
                            <tr><td><strong>{{ $line['description'] }}</strong>@if(($snapshot['document']['type'] ?? 'quote') === 'change_order')<br><span class="muted">{{ str($line['change_effect'] ?? 'add')->replace('_',' ')->headline() }}</span>@endif<br><span class="muted">{{ $line['customer_description'] }}</span></td><td>{{ rtrim(rtrim(number_format($line['quantity_millis'] / 1000, 3, '.', ''), '0'), '.') }} {{ $line['unit_name'] }}</td>@if($presentation['show_line_details'])<td class="right">${{ number_format($line['total_cents'] / 100, 2) }}</td>@endif</tr>
                        @endif
                    @endforeach
                    </tbody>
                </table>
                <div style="margin:20px 0 0 auto;width:260px">
                    <p>Subtotal <span style="float:right">${{ number_format($snapshot['totals']['subtotal_cents'] / 100, 2) }}</span></p>
                    <p>Discount <span style="float:right">−${{ number_format(($snapshot['totals']['line_discount_cents'] + $snapshot['totals']['quote_discount_cents']) / 100, 2) }}</span></p>
                    <p>Tax <span style="float:right">${{ number_format($snapshot['totals']['tax_cents'] / 100, 2) }}</span></p>
                    <p class="total">Total <span style="float:right">${{ number_format($snapshot['totals']['total_cents'] / 100, 2) }}</span></p>
                    @if(($snapshot['document']['type'] ?? 'quote') === 'change_order')<p>Change Order delta <span style="float:right">{{ $snapshot['totals']['change_order_delta_cents'] < 0 ? '−' : '+' }}${{ number_format(abs($snapshot['totals']['change_order_delta_cents']) / 100, 2) }}</span></p><p class="total">Revised Project total <span style="float:right">${{ number_format($snapshot['totals']['resulting_project_total_cents'] / 100, 2) }}</span></p>@endif
                </div>
            </section>
            @break
        @case('media')
            @if($snapshot['media'] !== [])
                <section>
                    <h2>{{ $templateSection['heading'] }}</h2>
                    <ul>
                        @foreach($snapshot['media'] as $media)
                            <li>{{ $media['caption'] ?: ($media['original_name'] ?: 'Embedded video') }}@if($media['media_type'] === 'video') — {{ $media['embed_url'] }}@endif</li>
                        @endforeach
                    </ul>
                </section>
            @endif
            @break
        @case('terms')
            <section class="page">
                <h2>{{ $templateSection['heading'] ?: ($snapshot['terms']['name'] ?: 'Terms') }}</h2>
                <p style="white-space:pre-line">{{ $snapshot['terms']['body'] }}</p>
            </section>
            @break
    @endswitch
@endforeach
<p class="muted" style="margin-top:24px">Immutable publication {{ $publication->publication_hash }}</p>
</body>
</html>
