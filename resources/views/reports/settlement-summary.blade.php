<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Settlement Summary - {{ $data['period']['id'] }}</title>
    <style>
        body {
            font-family: 'Helvetica', 'Arial', sans-serif;
            font-size: 12px;
            color: #333;
            line-height: 1.4;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #444;
            padding-bottom: 10px;
        }
        .header h1 {
            margin: 0;
            font-size: 24px;
            color: #111;
        }
        .section-title {
            background-color: #f4f4f4;
            padding: 8px;
            font-weight: bold;
            font-size: 14px;
            margin-top: 20px;
            margin-bottom: 10px;
            border-left: 4px solid #333;
        }
        .info-grid {
            width: 100%;
            margin-bottom: 20px;
        }
        .info-grid td {
            padding: 4px 0;
        }
        .label {
            font-weight: bold;
            width: 150px;
        }
        table.data-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        table.data-table th {
            background-color: #eee;
            text-align: left;
            padding: 8px;
            border: 1px solid #ddd;
            font-weight: bold;
        }
        table.data-table td {
            padding: 8px;
            border: 1px solid #ddd;
        }
        .amount {
            text-align: right;
            font-family: 'Courier', monospace;
        }
        .total-row {
            font-weight: bold;
            background-color: #f9f9f9;
        }
        .footer {
            position: fixed;
            bottom: 0;
            width: 100%;
            text-align: center;
            font-size: 10px;
            color: #777;
            border-top: 1px solid #ddd;
            padding-top: 5px;
        }
        .status-badge {
            text-transform: uppercase;
            font-weight: bold;
            font-size: 10px;
            padding: 2px 6px;
            border-radius: 4px;
            background-color: #eee;
        }
        .source-mode {
            float: right;
            font-size: 10px;
            color: #666;
            font-style: italic;
        }
    </style>
</head>
<body>

    <div class="header">
        <h1>Settlement Summary Report</h1>
        <div class="source-mode">Source: {{ strtoupper($sourceMode) }}</div>
    </div>

    <div class="section-title">Period Information</div>
    <table class="info-grid">
        <tr>
            <td class="label">Settlement Period ID:</td>
            <td>{{ $data['period']['id'] }}</td>
        </tr>
        <tr>
            <td class="label">Branch:</td>
            <td>{{ $branchName ?? 'Tenant-Wide' }}</td>
        </tr>
        <tr>
            <td class="label">Period Range:</td>
            <td>{{ $data['period']['period_start_at'] }} to {{ $data['period']['period_end_at'] }}</td>
        </tr>
        <tr>
            <td class="label">Status:</td>
            <td><span class="status-badge">{{ $data['period']['status'] }}</span></td>
        </tr>
    </table>

    <div class="section-title">Financial Performance</div>
    <table class="data-table">
        <thead>
            <tr>
                <th>Category</th>
                <th class="amount">Count</th>
                <th class="amount">Total Amount</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Gross Sales</td>
                <td class="amount">{{ $data['sales']['sale_count'] }}</td>
                <td class="amount">{{ $data['sales']['gross_sales_total'] }}</td>
            </tr>
            <tr>
                <td>Voids</td>
                <td class="amount">{{ $data['sales']['void_count'] }}</td>
                <td class="amount">({{ $data['sales']['void_total'] }})</td>
            </tr>
            <tr>
                <td>Refunds</td>
                <td class="amount">{{ $data['sales']['refund_count'] }}</td>
                <td class="amount">({{ $data['sales']['refund_total'] }})</td>
            </tr>
            <tr class="total-row">
                <td>Net Sales</td>
                <td></td>
                <td class="amount">{{ $data['sales']['net_sales_total'] }}</td>
            </tr>
        </tbody>
    </table>

    <div class="section-title">Payment Method Breakdown</div>
    <table class="data-table">
        <thead>
            <tr>
                <th>Method Code</th>
                <th>Method Name</th>
                <th class="amount">Total Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach($data['payments']['by_method'] as $method)
            <tr>
                <td>{{ $method['code'] }}</td>
                <td>{{ $method['name'] }}</td>
                <td class="amount">{{ $method['total'] }}</td>
            </tr>
            @endforeach
            <tr class="total-row">
                <td colspan="2">Total Payments Collected</td>
                <td class="amount">{{ $data['payments']['total'] }}</td>
            </tr>
        </tbody>
    </table>

    <div class="section-title">Accounting Sync Status</div>
    <table class="data-table">
        <thead>
            <tr>
                <th>Sync Status</th>
                <th class="amount">Record Count</th>
            </tr>
        </thead>
        <tbody>
            @foreach($data['accounting_sync'] as $status => $count)
            <tr>
                <td>{{ ucfirst($status) }}</td>
                <td class="amount">{{ $count }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="footer">
        Generated by IPOS - System Identity: {{ config('app.name') }} | Timestamp: {{ now()->toDateTimeString() }} | User: {{ $actorName }}
    </div>

</body>
</html>
