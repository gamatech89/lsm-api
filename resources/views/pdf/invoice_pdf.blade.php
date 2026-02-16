<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: DejaVu Sans, Arial, sans-serif;
            font-size: 12px;
            color: #333;
            line-height: 1.5;
            padding: 50px 50px 40px;
        }

        /* ===== Header: From (left) + Invoice title (right) ===== */
        .header {
            display: table;
            width: 100%;
            margin-bottom: 30px;
        }
        .header-left {
            display: table-cell;
            width: 55%;
            vertical-align: top;
        }
        .header-right {
            display: table-cell;
            width: 45%;
            vertical-align: top;
            text-align: right;
        }
        .from-label {
            font-size: 10px;
            color: #999;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 6px;
        }
        .from-name {
            font-size: 15px;
            font-weight: bold;
            color: #111;
            margin-bottom: 4px;
        }
        .from-address {
            font-size: 11px;
            color: #555;
            line-height: 1.7;
        }
        .invoice-title {
            font-size: 32px;
            font-weight: bold;
            color: #111;
            letter-spacing: 2px;
            margin-bottom: 10px;
        }
        .invoice-number-box {
            display: inline-block;
            border: 1px solid #ccc;
            padding: 6px 16px;
            font-size: 13px;
            color: #333;
            font-weight: bold;
            margin-bottom: 16px;
        }

        /* ===== Meta info: Date + Balance Due (right-aligned) ===== */
        .meta-section {
            display: table;
            width: 100%;
            margin-bottom: 30px;
        }
        .meta-left {
            display: table-cell;
            width: 55%;
            vertical-align: top;
        }
        .meta-right {
            display: table-cell;
            width: 45%;
            vertical-align: top;
        }
        .meta-row {
            display: table;
            width: 100%;
            margin-bottom: 4px;
        }
        .meta-label {
            display: table-cell;
            text-align: right;
            padding-right: 12px;
            font-size: 11px;
            color: #888;
            width: 50%;
        }
        .meta-value {
            display: table-cell;
            font-size: 12px;
            color: #111;
            font-weight: bold;
            text-align: right;
        }

        /* ===== Bill To ===== */
        .bill-to {
            margin-bottom: 30px;
        }
        .bill-to-label {
            font-size: 10px;
            color: #999;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 6px;
        }
        .bill-to-name {
            font-size: 13px;
            font-weight: bold;
            color: #111;
            margin-bottom: 2px;
        }
        .bill-to-address {
            font-size: 11px;
            color: #555;
            line-height: 1.7;
        }

        /* ===== Items Table ===== */
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 24px;
        }
        .items-table thead th {
            background-color: #1a1a1a;
            color: #fff;
            padding: 10px 14px;
            text-align: left;
            font-size: 10px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .items-table thead th.right {
            text-align: right;
        }
        .items-table thead th.center {
            text-align: center;
        }
        .items-table tbody td {
            padding: 12px 14px;
            border-bottom: 1px solid #e8e8e8;
            vertical-align: top;
            font-size: 11px;
        }
        .items-table tbody td.right {
            text-align: right;
        }
        .items-table tbody td.center {
            text-align: center;
        }
        .item-number {
            color: #aaa;
            font-size: 10px;
        }
        .item-description {
            color: #333;
        }
        .item-project {
            color: #2563eb;
            font-size: 10px;
        }
        .item-task {
            color: #777;
            font-size: 10px;
        }

        /* ===== Totals ===== */
        .totals {
            width: 100%;
            margin-top: 8px;
        }
        .totals-row {
            display: table;
            width: 100%;
        }
        .totals-spacer {
            display: table-cell;
            width: 60%;
        }
        .totals-label {
            display: table-cell;
            width: 20%;
            text-align: right;
            padding: 8px 14px;
            font-size: 12px;
            color: #666;
        }
        .totals-value {
            display: table-cell;
            width: 20%;
            text-align: right;
            padding: 8px 14px;
            font-size: 12px;
            color: #333;
            font-weight: bold;
            border-bottom: 1px solid #e8e8e8;
        }
        .totals-row.total-final .totals-label {
            font-size: 14px;
            font-weight: bold;
            color: #111;
            padding-top: 12px;
        }
        .totals-row.total-final .totals-value {
            font-size: 14px;
            font-weight: bold;
            color: #111;
            border-bottom: 2px solid #1a1a1a;
            padding-top: 12px;
        }

        /* ===== Notes ===== */
        .notes {
            margin-top: 30px;
            padding: 12px 14px;
            background-color: #f8f8f8;
            border-left: 3px solid #ddd;
            font-size: 11px;
            color: #666;
        }
        .notes-title {
            font-weight: bold;
            margin-bottom: 4px;
            color: #555;
        }

        /* ===== Footer ===== */
        .footer {
            margin-top: 50px;
            padding-top: 16px;
            border-top: 1px solid #e5e5e5;
            font-size: 9px;
            color: #bbb;
            text-align: center;
        }
    </style>
</head>
<body>
    <!-- Header: From info (left) + INVOICE title (right) -->
    <div class="header">
        <div class="header-left">
            <div class="from-label">From</div>
            <div class="from-name">{{ $fromName }}</div>
            <div class="from-address">
                @if($user->billing_address)
                    {!! nl2br(e($user->billing_address)) !!}
                @endif
            </div>
        </div>
        <div class="header-right">
            <div class="invoice-title">INVOICE</div>
            <div class="invoice-number-box">{{ $customInvoiceNumber }}</div>
        </div>
    </div>

    <!-- Meta info (right) + Bill To (left) -->
    <div class="meta-section">
        <div class="meta-left">
            <div class="bill-to-label">Bill To</div>
            <div class="bill-to-name">{{ $billTo['company_name'] }}</div>
            <div class="bill-to-address">
                {!! nl2br(e($billTo['company_address'])) !!}
            </div>
        </div>
        <div class="meta-right">
            <div class="meta-row">
                <div class="meta-label">Date:</div>
                <div class="meta-value">{{ $invoice->created_at->format('d.m.Y') }}</div>
            </div>
            <div class="meta-row" style="margin-top: 8px;">
                <div class="meta-label">Balance Due:</div>
                <div class="meta-value" style="font-size: 14px;">{{ $currency }}{{ number_format($total, 2) }}</div>
            </div>
        </div>
    </div>

    <!-- Items Table -->
    <table class="items-table">
        <thead>
            <tr>
                <th style="width: 55%">Item</th>
                <th class="center" style="width: 12%">Hours</th>
                <th class="right" style="width: 15%">Rate</th>
                <th class="right" style="width: 18%">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach($lineItems as $index => $item)
            <tr>
                <td>
                    <span class="item-number">{{ str_pad($index + 1, 3, '0', STR_PAD_LEFT) }}.</span>
                    <span class="item-description">{{ $item['description'] }}</span>
                    @if($item['project_url'])
                        <br><span class="item-project">{{ $item['project_slug'] }}</span>
                    @endif
                    @if(!empty($item['tasks']))
                        @foreach($item['tasks'] as $task)
                            <br><span class="item-task">– {{ $task }}</span>
                        @endforeach
                    @endif
                </td>
                <td class="center">{{ number_format($item['hours'], 1) }}</td>
                <td class="right">{{ $currency }}{{ number_format($item['rate'], 2) }}/h</td>
                <td class="right">{{ $currency }}{{ number_format($item['amount'], 2) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <!-- Totals (no VAT row) -->
    <div class="totals">
        @if(count($lineItems) > 1)
        <div class="totals-row">
            <div class="totals-spacer"></div>
            <div class="totals-label">Subtotal:</div>
            <div class="totals-value">{{ $currency }}{{ number_format($subtotal, 2) }}</div>
        </div>
        @endif
        <div class="totals-row total-final">
            <div class="totals-spacer"></div>
            <div class="totals-label">Total:</div>
            <div class="totals-value">{{ $currency }}{{ number_format($total, 2) }}</div>
        </div>
    </div>

    <!-- Notes -->
    @if($invoice->notes)
    <div class="notes">
        <div class="notes-title">Notes:</div>
        {{ $invoice->notes }}
    </div>
    @endif

    <!-- Footer -->
    <div class="footer">
        Invoice {{ $customInvoiceNumber }} &middot; {{ $invoice->created_at->format('Y') }}
    </div>
</body>
</html>
