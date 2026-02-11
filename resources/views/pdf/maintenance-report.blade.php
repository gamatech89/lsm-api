<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <title>Maintenance Report - {{ $report->project->name }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 12px;
            line-height: 1.5;
            color: #333;
            padding: 30px;
        }

        .header {
            border-bottom: 3px solid #6B21A8;
            padding-bottom: 20px;
            margin-bottom: 25px;
            display: table;
            width: 100%;
        }

        .logo-section {
            display: table-cell;
            width: 200px;
            vertical-align: middle;
        }

        .logo {
            max-width: 180px;
            max-height: 50px;
        }

        .title-section {
            display: table-cell;
            vertical-align: middle;
            text-align: right;
        }

        .header h1 {
            color: #6B21A8;
            font-size: 24px;
            margin-bottom: 5px;
        }

        .header .subtitle {
            color: #666;
            font-size: 14px;
        }

        .project-url {
            font-size: 11px;
            color: #3AA68D;
            text-decoration: none;
        }

        .meta-row {
            display: table;
            width: 100%;
            margin-bottom: 20px;
            background: #f8f9fa;
            padding: 12px;
            border-radius: 6px;
            border: 1px solid #e9ecef;
        }

        .meta-item {
            display: table-cell;
            width: 20%;
        }

        .meta-label {
            color: #666;
            font-size: 10px;
            text-transform: uppercase;
            display: block;
            margin-bottom: 2px;
        }

        .meta-value {
            font-weight: bold;
            color: #333;
        }

        .meta-value-highlight {
            font-weight: bold;
            color: #3AA68D;
        }

        .section {
            margin-bottom: 20px;
        }

        .section-title {
            color: #6B21A8;
            font-size: 14px;
            font-weight: bold;
            margin-bottom: 10px;
            padding-bottom: 5px;
            border-bottom: 1px solid #e0e0e0;
        }

        .content-box {
            background: #f8f9fa;
            padding: 12px;
            border-radius: 6px;
            border-left: 4px solid #6B21A8;
        }

        ul {
            margin: 0;
            padding-left: 20px;
        }

        li {
            margin-bottom: 5px;
        }

        .badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: bold;
            text-transform: uppercase;
        }

        .badge-monthly {
            background: #6B21A8;
            color: #fff;
        }

        .badge-weekly {
            background: #3b82f6;
            color: #fff;
        }

        .badge-adhoc {
            background: #f59e0b;
            color: #fff;
        }

        .footer {
            margin-top: 30px;
            padding-top: 15px;
            border-top: 2px solid #e0e0e0;
            font-size: 10px;
            color: #999;
            text-align: center;
        }

        .two-column {
            display: table;
            width: 100%;
        }

        .column {
            display: table-cell;
            width: 50%;
            vertical-align: top;
            padding-right: 10px;
        }

        .column:last-child {
            padding-right: 0;
            padding-left: 10px;
        }
    </style>
</head>

<body>
    <div class="header">
        <div class="logo-section">
            {{-- Landeseiten - Text only logo for PDF compatibility --}}
            <div style="font-size: 22px; font-weight: bold; color: #6B21A8;">Landeseiten</div>
            <div style="font-size: 11px; color: #999; margin-top: 2px;">Website Maintenance</div>
        </div>
        <div class="title-section">
            <h1>Maintenance Report</h1>
            <div class="subtitle">{{ $report->project->name }}</div>
            @if($report->project->url)
                <a href="{{ $report->project->url }}" class="project-url">{{ $report->project->url }}</a>
            @endif
        </div>
    </div>

    <div class="meta-row">
        <div class="meta-item">
            <span class="meta-label">Report Date</span>
            <span class="meta-value">{{ \Carbon\Carbon::parse($report->report_date)->format('F j, Y') }}</span>
        </div>
        <div class="meta-item">
            <span class="meta-label">Report Type</span>
            <span class="badge badge-{{ $report->type === 'ad-hoc' ? 'adhoc' : $report->type }}">
                {{ ucfirst(str_replace('-', ' ', $report->type)) }}
            </span>
        </div>
        <div class="meta-item">
            <span class="meta-label">Developer</span>
            <span class="meta-value">{{ $report->user->name ?? 'Unknown' }}</span>
        </div>
        <div class="meta-item">
            <span class="meta-label">Time Spent</span>
            <span class="meta-value">
                @if($report->time_spent_minutes)
                @php
                $hours = floor($report->time_spent_minutes / 60);
                $mins = $report->time_spent_minutes % 60;
                @endphp
                {{ $hours > 0 ? $hours . 'h ' : '' }}{{ $mins > 0 ? $mins . 'm' : ($hours > 0 ? '' : '0m') }}
                @else
                Not tracked
                @endif
            </span>
        </div>
        <div class="meta-item">
            <span class="meta-label">Cost</span>
            @php
                $hourlyRate = $report->user->hourly_rate ?? 22;
                $hoursDecimal = $report->time_spent_minutes ? ($report->time_spent_minutes / 60) : 0;
                $totalCost = $hoursDecimal * $hourlyRate;
            @endphp
            <span class="meta-value-highlight">
                @if($report->time_spent_minutes)
                    €{{ number_format($totalCost, 2) }}
                    <span style="font-weight: normal; font-size: 9px; color: #666;">(€{{ number_format($hourlyRate, 2) }}/hr)</span>
                @else
                    -
                @endif
            </span>
        </div>
    </div>

    <div class="section">
        <div class="section-title">Summary</div>
        <div class="content-box">
            {{ $report->summary }}
        </div>
    </div>

    @if($report->tasks_completed && count($report->tasks_completed) > 0)
    <div class="section">
        <div class="section-title">✓ Tasks Completed</div>
        <div class="content-box">
            <ul>
                @foreach($report->tasks_completed as $task)
                <li>{{ $task }}</li>
                @endforeach
            </ul>
        </div>
    </div>
    @endif

    @if($report->updates_performed && count($report->updates_performed) > 0)
    <div class="section">
        <div class="section-title">↑ Updates Performed</div>
        <div class="content-box">
            <ul>
                @foreach($report->updates_performed as $update)
                <li>{{ is_array($update) ? ($update['name'] ?? $update) : $update }}</li>
                @endforeach
            </ul>
        </div>
    </div>
    @endif

    @if($report->issues_found && count($report->issues_found) > 0)
    <div class="section">
        <div class="section-title">⚠ Issues & Out of Scope Tasks</div>
        <div class="content-box">
            <ul style="list-style: none; padding-left: 0;">
                @foreach($report->issues_found as $issue)
                @php
                    $isResolved = in_array($issue, $report->issues_resolved ?? []);
                @endphp
                <li style="margin-bottom: 6px; display: flex; align-items: flex-start;">
                    <span style="margin-right: 8px; color: {{ $isResolved ? '#52c41a' : '#faad14' }}; font-weight: bold;">
                        {{ $isResolved ? '✓' : '○' }}
                    </span>
                    <span style="{{ $isResolved ? 'text-decoration: line-through; color: #888;' : '' }}">
                        {{ $issue }}
                    </span>
                    @if($isResolved)
                    <span style="margin-left: 8px; font-size: 10px; background: #f6ffed; color: #52c41a; padding: 1px 6px; border-radius: 4px;">Resolved</span>
                    @else
                    <span style="margin-left: 8px; font-size: 10px; background: #fffbe6; color: #faad14; padding: 1px 6px; border-radius: 4px;">Open</span>
                    @endif
                </li>
                @endforeach
            </ul>
        </div>
    </div>
    @endif

    @if($report->notes)
    <div class="section">
        <div class="section-title">Additional Notes</div>
        <div class="content-box">
            {!! nl2br(e($report->notes)) !!}
        </div>
    </div>
    @endif

    <div class="footer">
        Generated on {{ now()->format('F j, Y \a\t g:i A') }} • LSM - Landeseiten Maintenance
    </div>
</body>

</html>