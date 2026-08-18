@php
    /** @var \Metered\Invoicing\Infrastructure\Eloquent\Invoice $invoice */
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $invoice->printedNumber() ?? 'Draft invoice' }}</title>
    <style>
        @page { margin: 48px 56px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #1f2933; }
        h1 { font-size: 20px; margin: 0 0 4px; }
        .muted { color: #6b7280; }
        .meta { width: 100%; margin: 24px 0; border-collapse: collapse; }
        .meta td { vertical-align: top; padding: 0; width: 50%; }
        .label { text-transform: uppercase; font-size: 8px; letter-spacing: .06em; color: #6b7280; }
        table.lines { width: 100%; border-collapse: collapse; }
        table.lines th { text-align: left; font-size: 8px; text-transform: uppercase; color: #6b7280; border-bottom: 1px solid #d1d5db; padding: 6px 4px; }
        table.lines td { padding: 8px 4px 2px; vertical-align: top; }
        table.lines tr.working td { padding: 0 4px 8px; border-bottom: 1px solid #f0f1f3; font-size: 8px; color: #6b7280; }
        .num { text-align: right; white-space: nowrap; }
        .late { color: #b45309; font-size: 8px; text-transform: uppercase; }
        .total td { padding-top: 12px; font-size: 12px; font-weight: bold; }
        .stamp { margin-top: 24px; padding: 8px 12px; border: 1px solid #b91c1c; color: #b91c1c; }
    </style>
</head>
<body>
    <h1>Invoice {{ $invoice->printedNumber() ?? '(draft)' }}</h1>
    <div class="muted">{{ ucfirst($invoice->status->value) }}</div>

    <table class="meta">
        <tr>
            <td>
                <div class="label">Bill to</div>
                <div>{{ $invoice->customer_name }}</div>
                <div class="muted">{{ $invoice->customer_ref }}</div>
            </td>
            <td>
                <div class="label">Period</div>
                <div>{{ $invoice->period_start->format('Y-m-d H:i') }} – {{ $invoice->period_end->format('Y-m-d H:i') }} UTC</div>
                <div class="label" style="margin-top: 8px">Issued</div>
                <div>{{ $invoice->finalized_at?->format('Y-m-d') ?? 'not yet' }}</div>
            </td>
        </tr>
    </table>

    <table class="lines">
        <thead>
            <tr><th>Description</th><th class="num">Quantity</th><th class="num">Amount</th></tr>
        </thead>
        <tbody>
            @foreach ($invoice->orderedLines() as $line)
                <tr>
                    <td>
                        {{ $line->description }}
                        @if ($line->kind === \Metered\Invoicing\Domain\Invoice\LineKind::Late)
                            <span class="late">late</span>
                        @endif
                    </td>
                    <td class="num">{{ $line->quantity ?? '' }}</td>
                    <td class="num">{{ $line->amount() }}</td>
                </tr>
                <tr class="working">
                    <td colspan="3">{{ implode(' · ', $line->calculation) }}</td>
                </tr>
            @endforeach
            <tr class="total">
                <td colspan="2">Total</td>
                <td class="num">{{ $invoice->total() }}</td>
            </tr>
        </tbody>
    </table>

    @if ($invoice->creditNote)
        <div class="stamp">
            Void. Reversed in full by credit note {{ $invoice->creditNote->printedNumber() }}
            of {{ $invoice->creditNote->issued_at->format('Y-m-d') }}: {{ $invoice->creditNote->reason }}
        </div>
    @endif
</body>
</html>
