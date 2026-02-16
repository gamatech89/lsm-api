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
            padding: 40px;
        }

        /* Header */
        .header {
            display: table;
            width: 100%;
            margin-bottom: 20px;
        }
        .header-left {
            display: table-cell;
            width: 60%;
            vertical-align: top;
        }
        .header-right {
            display: table-cell;
            width: 40%;
            vertical-align: top;
            text-align: right;
        }
        .invoice-title {
            font-size: 28px;
            font-weight: bold;
            color: #111;
            margin-bottom: 8px;
        }
        .invoice-number {
            font-size: 14px;
            color: #555;
            border: 1px solid #ddd;
            padding: 4px 12px;
            display: inline-block;
            margin-bottom: 6px;
        }
        .label {
            font-size: 11px;
            color: #888;
            margin-bottom: 2px;
        }
        .value {
            font-size: 12px;
            color: #333;
            font-weight: bold;
        }

        /* Info rows */
        .info-row {
            display: table;
            width: 100%;
            margin-bottom: 6px;
        }
        .info-row .info-label {
            display: table-cell;
            width: 80px;
            text-align: right;
            padding-right: 10px;
            font-size: 11px;
            color: #888;
        }
        .info-row .info-value {
            display: table-cell;
            font-size: 12px;
            color: #333;
            font-weight: bold;
        }

        /* Addresses */
        .addresses {
            display: table;
            width: 100%;
            margin-bottom: 30px;
        }
        .address-from {
            display: table-cell;
            width: 55%;
            vertical-align: top;
        }
        .address-to {
            display: table-cell;
            width: 45%;
            vertical-align: top;
        }
        .address-label {
            font-size: 11px;
            color: #888;
            margin-bottom: 4px;
        }
        .address-text {
            font-size: 12px;
            color: #333;
            line-height: 1.6;
        }
        .company-name {
            font-weight: bold;
            font-size: 13px;
        }

        /* Items table */
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        .items-table thead th {
            background-color: #1a1a1a;
            color: #fff;
            padding: 10px 12px;
            text-align: left;
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
        }
        .items-table thead th.right {
            text-align: right;
        }
        .items-table tbody td {
            padding: 12px;
            border-bottom: 1px solid #e5e5e5;
            vertical-align: top;
            font-size: 12px;
        }
        .items-table tbody td.right {
            text-align: right;
        }
        .item-number {
            color: #888;
            font-size: 11px;
        }
        .item-description {
            color: #333;
        }
        .item-project {
            color: #2563eb;
            font-size: 11px;
            text-decoration: none;
        }

        /* Totals */
        .totals {
            width: 100%;
            margin-top: 10px;
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
            padding: 6px 12px;
            font-size: 12px;
            color: #555;
        }
        .totals-value {
            display: table-cell;
            width: 20%;
            text-align: right;
            padding: 6px 12px;
            font-size: 12px;
            color: #333;
            font-weight: bold;
            border: 1px solid #e5e5e5;
        }
        .totals-row.total-final .totals-label {
            font-size: 13px;
            font-weight: bold;
            color: #111;
        }
        .totals-row.total-final .totals-value {
            font-size: 13px;
            font-weight: bold;
            color: #111;
            border: 2px solid #1a1a1a;
        }

        /* Footer */
        .footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #e5e5e5;
            font-size: 10px;
            color: #aaa;
            text-align: center;
        }

        /* Notes */
        .notes {
            margin-top: 30px;
            padding: 12px;
            background-color: #f9f9f9;
            border-left: 3px solid #ddd;
            font-size: 11px;
            color: #666;
        }
        .notes-title {
            font-weight: bold;
            margin-bottom: 4px;
            color: #555;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <div class="header">
        <div class="header-left">
            <div class="address-label">Company Address:</div>
            <div class="address-text">
                @if($user->billing_company_name)
                    <span class="company-name">{{ $user->billing_company_name }}</span><br>
                @else
                    <span class="company-name">{{ $user->name }}</span><br>
                @endif
                @if($user->billing_address)
                    {!! nl2br(e($user->billing_address)) !!}
                @endif
            </div>
        </div>
        <div class="header-right">
            <div class="invoice-title">INVOICE</div>
            <div class="invoice-number">{{ $invoice->invoice_number }}</div>
            <br><br>
            <div class="info-row">
                <span class="info-label">Date:</span>
                <span class="info-value">{{ $invoice->created_at->format('d.m.Y') }}</span>
            </div>
            <div class="info-row">
                <span class="info-label">Balance Due:</span>
                <span class="info-value">{{ $currency }}{{ number_format($invoice->total_amount, 0) }}</span>
            </div>
        </div>
    </div>

    <!-- Bill To -->
    <div class="addresses">
        <div class="address-from"></div>
        <div class="address-to">
            <div class="address-label">Bill to:</div>
            <div class="address-text">
                <span class="company-name">{{ $billTo['company_name'] }}</span><br>
                {!! nl2br(e($billTo['company_address'])) !!}
            </div>
        </div>
    </div>

    <!-- Items Table -->
    <table class="items-table">
        <thead>
            <tr>
                <th style="width: 60%">Item</th>
                <th class="right" style="width: 13%">Qty</th>
                <th class="right" style="width: 13%">Rate</th>
                <th class="right" style="width: 14%">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach($lineItems as $index => $item)
            <tr>
                <td>
                    <span class="item-number">{{ str_pad($index + 1, 3, '0', STR_PAD_LEFT) }}.</span>
                    {{ $item['description'] }}
                    @if($item['project_url'])
                        <br><span class="item-project">#{{ $item['project_slug'] }}</span>
                    @endif
                    @if(!empty($item['tasks']))
                        @foreach($item['tasks'] as $task)
                            <br><span style="color: #666; font-size: 10px;">– {{ $task }}</span>
                        @endforeach
                    @endif
                </td>
                <td class="right">{{ number_format($item['hours'], 1) }}h</td>
                <td class="right">{{ $currency }}{{ number_format($item['rate'], 0) }}/h</td>
                <td class="right">{{ $currency }}{{ number_format($item['amount'], 0) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <!-- Totals -->
    <div class="totals">
        <div class="totals-row">
            <div class="totals-spacer"></div>
            <div class="totals-label">Subtotal:</div>
            <div class="totals-value">{{ $currency }}{{ number_format($subtotal, 0) }}</div>
        </div>
        <div class="totals-row">
            <div class="totals-spacer"></div>
            <div class="totals-label">{{ $taxLabel }} ({{ $taxRate }}%):</div>
            <div class="totals-value">{{ $currency }}{{ number_format($taxAmount, 0) }}</div>
        </div>
        <div class="totals-row total-final">
            <div class="totals-spacer"></div>
            <div class="totals-label">Total:</div>
            <div class="totals-value">{{ $currency }}{{ number_format($total, 0) }}</div>
        </div>
    </div>

    <!-- Notes -->
    @if($invoice->notes)
    <div class="notes">
        <div class="notes-title">Notes:</div>
        {{ $invoice->notes }}
    </div>
    @endif

    <!-- Tax ID -->
    @if($user->billing_tax_id)
    <div style="margin-top: 20px; font-size: 11px; color: #666;">
        Tax ID: {{ $user->billing_tax_id }}
    </div>
    @endif

    <!-- Footer -->
    <div class="footer">
        Invoice {{ $invoice->invoice_number }} &middot; Period: {{ $invoice->period_start->format('d.m.Y') }} – {{ $invoice->period_end->format('d.m.Y') }}
    </div>
</body>
</html>
