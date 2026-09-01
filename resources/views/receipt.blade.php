<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- A receipt is not something to be found in a search result. --}}
    <meta name="robots" content="noindex, nofollow">
    <title>Receipt {{ $invoice['number'] ?? '' }}</title>
    <style>
        /*
           Printable first. Most people open this on a phone from WhatsApp and
           some of them will want a copy for their own records, so "print to
           PDF" has to produce something that looks like a document rather than
           a screenshot of a web page.
        */
        :root {
            --ink: #1C1917;
            --body: #44403C;
            --muted: #79716B;
            --line: #E7E5E4;
            --accent: #EA580C;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            padding: 24px 16px 64px;
            background: #F5F5F4;
            color: var(--body);
            font: 15px/1.6 "Segoe UI", -apple-system, Roboto, Helvetica, Arial, sans-serif;
        }

        .sheet {
            max-width: 640px;
            margin: 0 auto;
            background: #FFFFFF;
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 28px;
        }

        h1 { margin: 0; font-size: 20px; color: var(--ink); letter-spacing: -0.01em; }

        .head { display: flex; flex-wrap: wrap; gap: 12px; align-items: baseline; border-bottom: 2px solid var(--accent); padding-bottom: 14px; }
        .head .num { margin-left: auto; font-size: 13px; color: var(--muted); }

        .paid {
            margin: 18px 0;
            padding: 14px 16px;
            background: #F0FDF4;
            border: 1px solid #BBF7D0;
            border-radius: 8px;
            color: #14532D;
        }
        .paid strong { font-size: 22px; display: block; }

        .parties { display: flex; flex-wrap: wrap; gap: 24px; margin: 22px 0; }
        .parties > div { flex: 1 1 220px; min-width: 0; }

        .label { font-size: 11px; text-transform: uppercase; letter-spacing: .08em; color: var(--muted); margin-bottom: 4px; }
        .name { color: var(--ink); font-weight: 600; }

        table { width: 100%; border-collapse: collapse; margin: 18px 0; font-size: 14px; }
        th { text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .08em; color: var(--muted); border-bottom: 1px solid var(--line); padding: 0 0 6px; }
        td { padding: 10px 0; border-bottom: 1px solid var(--line); vertical-align: top; }
        td.amount, th.amount { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }

        .total { display: flex; justify-content: space-between; align-items: baseline; padding-top: 12px; font-size: 18px; color: var(--ink); font-weight: 650; }

        .meta { margin-top: 18px; font-size: 13px; color: var(--muted); }
        .meta div { margin-top: 2px; }

        .footer { margin-top: 22px; padding-top: 14px; border-top: 1px solid var(--line); font-size: 12px; color: var(--muted); white-space: pre-line; }

        .print { display: block; width: 100%; margin: 16px auto 0; max-width: 640px; padding: 12px; border: 1px solid var(--line); border-radius: 8px; background: #FFFFFF; color: var(--body); font: inherit; font-size: 14px; cursor: pointer; }

        @media print {
            body { background: #FFFFFF; padding: 0; }
            .sheet { border: 0; border-radius: 0; padding: 0; max-width: none; }
            .print { display: none; }
        }
    </style>
</head>
<body>
    <div class="sheet">
        <div class="head">
            <h1>{{ $invoice['from']['name'] ?: 'Receipt' }}</h1>
            @if ($invoice['number'])
                <span class="num">Receipt {{ $invoice['number'] }}</span>
            @endif
        </div>

        {{-- The one thing somebody opens this to check. --}}
        <div class="paid">
            <strong>{{ $invoice['total_formatted'] }}</strong>
            {{-- The space before @if matters: Blade only recognises a
                 directive at a token boundary, so "Received@if" compiles as
                 literal text and leaves the @endif with nothing to close. --}}
            Received
            @if ($invoice['issued_on'])
                on {{ \Illuminate\Support\Carbon::parse($invoice['issued_on'])->format('j M Y') }}
            @endif
        </div>

        <div class="parties">
            <div>
                <div class="label">From</div>
                <div class="name">{{ $invoice['from']['name'] }}</div>
                @if ($invoice['from']['address'])<div>{{ $invoice['from']['address'] }}</div>@endif
                @if ($invoice['from']['gstin'])<div>GSTIN {{ $invoice['from']['gstin'] }}</div>@endif
                @if ($invoice['from']['phone'])<div>{{ $invoice['from']['phone'] }}</div>@endif
            </div>

            <div>
                <div class="label">Billed to</div>
                <div class="name">{{ $invoice['to']['name'] }}</div>
                @if ($invoice['to']['address'])<div>{{ $invoice['to']['address'] }}</div>@endif
                @if ($invoice['to']['phone'])<div>{{ $invoice['to']['phone'] }}</div>@endif
            </div>
        </div>

        <table>
            <thead>
                <tr><th>What for</th><th class="amount">Amount</th></tr>
            </thead>
            <tbody>
                @foreach ($invoice['lines'] as $line)
                    <tr>
                        <td>
                            {{ $line['description'] }}
                            @if ($line['period'] && $line['period']['start'])
                                <div style="color: var(--muted); font-size: 13px;">
                                    {{ \Illuminate\Support\Carbon::parse($line['period']['start'])->format('j M Y') }}
                                    to
                                    {{ \Illuminate\Support\Carbon::parse($line['period']['end'])->format('j M Y') }}
                                </div>
                            @endif
                        </td>
                        <td class="amount">&#8377;{{ number_format($line['amount'], 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="total">
            <span>Total paid</span>
            <span>{{ $invoice['total_formatted'] }}</span>
        </div>

        <div class="meta">
            @if ($invoice['method'])<div>Paid by {{ $invoice['method'] }}</div>@endif
            @if ($invoice['reference'])<div>Reference {{ $invoice['reference'] }}</div>@endif
            @if ($invoice['paid_by_hand'])<div>Recorded at the office</div>@endif
        </div>

        @if ($invoice['footer'])
            <div class="footer">{{ $invoice['footer'] }}</div>
        @endif
    </div>

    <button type="button" class="print" onclick="window.print()">Print or save as PDF</button>
</body>
</html>
