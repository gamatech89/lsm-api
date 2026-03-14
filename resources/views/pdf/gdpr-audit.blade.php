<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <title>GDPR Audit Report - {{ $projectName }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 11px;
            line-height: 1.5;
            color: #333;
            padding: 30px;
        }

        .header {
            border-bottom: 3px solid #6366f1;
            padding-bottom: 20px;
            margin-bottom: 20px;
            display: table;
            width: 100%;
        }

        .logo-section {
            display: table-cell;
            width: 200px;
            vertical-align: middle;
        }

        .title-section {
            display: table-cell;
            vertical-align: middle;
            text-align: right;
        }

        .header h1 {
            color: #6366f1;
            font-size: 22px;
            margin-bottom: 3px;
        }

        .header .subtitle {
            color: #666;
            font-size: 13px;
        }

        .project-url {
            font-size: 11px;
            color: #6366f1;
            text-decoration: none;
        }

        /* Score section */
        .score-row {
            display: table;
            width: 100%;
            margin-bottom: 20px;
            background: #f8f9fa;
            padding: 15px;
            border-radius: 6px;
            border: 1px solid #e9ecef;
        }

        .score-item {
            display: table-cell;
            text-align: center;
            vertical-align: middle;
        }

        .score-circle {
            display: inline-block;
            width: 60px;
            height: 60px;
            line-height: 60px;
            border-radius: 50%;
            font-size: 22px;
            font-weight: bold;
            color: white;
            text-align: center;
        }

        .score-good { background: #22c55e; }
        .score-warning { background: #f59e0b; }
        .score-bad { background: #ef4444; }

        .meta-label {
            color: #666;
            font-size: 9px;
            text-transform: uppercase;
            display: block;
            margin-top: 4px;
        }

        .meta-value {
            font-weight: bold;
            font-size: 13px;
        }

        /* Sections */
        .section {
            margin-bottom: 16px;
        }

        .section-title {
            color: #6366f1;
            font-size: 13px;
            font-weight: bold;
            margin-bottom: 8px;
            padding-bottom: 4px;
            border-bottom: 1px solid #e0e0e0;
        }

        .content-box {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 6px;
            border-left: 4px solid #6366f1;
        }

        .summary-text {
            font-size: 12px;
            line-height: 1.6;
            padding: 10px;
            border-radius: 6px;
        }

        .summary-good { background: #f0fdf4; border-left: 4px solid #22c55e; }
        .summary-warning { background: #fffbeb; border-left: 4px solid #f59e0b; }
        .summary-bad { background: #fef2f2; border-left: 4px solid #ef4444; }

        /* Violations table */
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 10px;
        }

        th {
            background: #f1f5f9;
            padding: 6px 8px;
            text-align: left;
            font-size: 9px;
            text-transform: uppercase;
            color: #64748b;
            border-bottom: 2px solid #e2e8f0;
        }

        td {
            padding: 6px 8px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: top;
        }

        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 9px;
            font-weight: bold;
            text-transform: uppercase;
            color: white;
        }

        .badge-critical { background: #ef4444; }
        .badge-high { background: #f97316; }
        .badge-medium { background: #f59e0b; color: #333; }
        .badge-low { background: #94a3b8; }

        .badge-pass { background: #22c55e; }
        .badge-fail { background: #ef4444; }

        .legal-ref {
            font-family: monospace;
            font-size: 9px;
            background: #f1f5f9;
            padding: 2px 4px;
            border-radius: 3px;
        }

        .positive-item {
            margin-bottom: 4px;
            padding-left: 14px;
            position: relative;
        }

        .positive-item::before {
            content: "✓";
            position: absolute;
            left: 0;
            color: #22c55e;
            font-weight: bold;
        }

        .recommendation-row {
            margin-bottom: 6px;
            display: table;
            width: 100%;
        }

        .recommendation-priority {
            display: table-cell;
            width: 60px;
            vertical-align: top;
        }

        .recommendation-text {
            display: table-cell;
            vertical-align: top;
        }

        /* Two column */
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

        .footer {
            margin-top: 25px;
            padding-top: 12px;
            border-top: 2px solid #e0e0e0;
            font-size: 9px;
            color: #999;
            text-align: center;
        }

        .ai-badge {
            display: inline-block;
            background: #6366f1;
            color: white;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 9px;
            font-weight: bold;
        }

        .page-break {
            page-break-before: always;
        }
    </style>
</head>

<body>
    {{-- Header --}}
    <div class="header">
        <div class="logo-section">
            <div style="font-size: 22px; font-weight: bold; color: #6366f1;">Landeseiten</div>
            <div style="font-size: 11px; color: #999; margin-top: 2px;">GDPR Compliance Audit</div>
        </div>
        <div class="title-section">
            <h1>GDPR Audit Report</h1>
            <div class="subtitle">{{ $projectName }}</div>
            @if($url)
                <a href="{{ $url }}" class="project-url">{{ $url }}</a>
            @endif
        </div>
    </div>

    {{-- Score row --}}
    <div class="score-row">
        <div class="score-item" style="width: 20%;">
            <div class="score-circle {{ $score >= 80 ? 'score-good' : ($score >= 50 ? 'score-warning' : 'score-bad') }}">
                {{ $score }}
            </div>
            <span class="meta-label">Score</span>
        </div>
        <div class="score-item" style="width: 20%;">
            <span class="meta-value">{{ $verdict }}</span>
            <span class="meta-label">Verdict</span>
        </div>
        <div class="score-item" style="width: 15%;">
            <span class="meta-value">{{ $auditData['summary']['trackingRequests'] ?? 0 }}</span>
            <span class="meta-label">Trackers</span>
        </div>
        <div class="score-item" style="width: 15%;">
            <span class="meta-value">{{ $auditData['summary']['trackingCookies'] ?? 0 }}</span>
            <span class="meta-label">Tracking Cookies</span>
        </div>
        <div class="score-item" style="width: 15%;">
            <span class="meta-value">{{ ($auditData['summary']['cookieBannerDetected'] ?? false) ? 'Yes' : 'No' }}</span>
            <span class="meta-label">Banner</span>
        </div>
        <div class="score-item" style="width: 15%;">
            @if($auditData['aiEnhanced'] ?? false)
                <span class="ai-badge">AI-Enhanced</span>
            @else
                <span class="badge badge-low">Basic</span>
            @endif
            <span class="meta-label">Scan Type</span>
        </div>
    </div>

    {{-- AI Summary --}}
    @if(!empty($aiSummary['summary']))
    <div class="section">
        <div class="section-title">AI Compliance Analysis</div>
        <div class="summary-text {{ $score >= 80 ? 'summary-good' : ($score >= 50 ? 'summary-warning' : 'summary-bad') }}">
            {{ $aiSummary['summary'] }}
        </div>
    </div>
    @endif

    {{-- Violations --}}
    @if(!empty($aiSummary['violations']))
    <div class="section">
        <div class="section-title">Violations ({{ count($aiSummary['violations']) }})</div>
        <table>
            <thead>
                <tr>
                    <th style="width: 70px;">Severity</th>
                    <th style="width: 140px;">Issue</th>
                    <th>Details</th>
                    <th style="width: 120px;">Legal Ref</th>
                    <th style="width: 160px;">Recommendation</th>
                </tr>
            </thead>
            <tbody>
                @foreach($aiSummary['violations'] as $v)
                <tr>
                    <td><span class="badge badge-{{ $v['severity'] ?? 'medium' }}">{{ $v['severity'] ?? 'medium' }}</span></td>
                    <td><strong>{{ $v['title'] ?? '' }}</strong></td>
                    <td>{{ $v['description'] ?? '' }}</td>
                    <td><span class="legal-ref">{{ $v['legalRef'] ?? '' }}</span></td>
                    <td>{{ $v['recommendation'] ?? '' }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif

    {{-- What's Done Right --}}
    @if(!empty($aiSummary['positives']))
    <div class="section">
        <div class="section-title">What's Done Right</div>
        <div class="content-box">
            @foreach($aiSummary['positives'] as $p)
                <div class="positive-item">{{ $p }}</div>
            @endforeach
        </div>
    </div>
    @endif

    {{-- Recommendations --}}
    @if(!empty($aiSummary['recommendations']))
    <div class="section">
        <div class="section-title">Recommendations</div>
        <div class="content-box">
            @foreach($aiSummary['recommendations'] as $r)
                <div class="recommendation-row">
                    <div class="recommendation-priority">
                        <span class="badge badge-{{ ($r['priority'] ?? '') === 'high' ? 'critical' : (($r['priority'] ?? '') === 'medium' ? 'medium' : 'low') }}">
                            {{ $r['priority'] ?? 'low' }}
                        </span>
                    </div>
                    <div class="recommendation-text">{{ $r['action'] ?? '' }}</div>
                </div>
            @endforeach
        </div>
    </div>
    @endif

    {{-- Technical details: Checks --}}
    @if(!empty($auditData['checks']))
    <div class="section">
        <div class="section-title">Audit Checks</div>
        <table>
            <thead>
                <tr>
                    <th style="width: 60px;">Status</th>
                    <th style="width: 180px;">Check</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
                @foreach($auditData['checks'] as $check)
                <tr>
                    <td>
                        <span class="badge {{ ($check['status'] ?? '') === 'pass' ? 'badge-pass' : (($check['status'] ?? '') === 'warning' ? 'badge-medium' : 'badge-fail') }}">
                            {{ $check['status'] ?? 'fail' }}
                        </span>
                    </td>
                    <td><strong>{{ $check['name'] ?? '' }}</strong></td>
                    <td>{{ $check['detail'] ?? '' }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif

    {{-- Tracking Services --}}
    @if(!empty($auditData['trackingByService']))
    <div class="section">
        <div class="section-title">Tracking Services Detected</div>
        <div class="content-box">
            @foreach($auditData['trackingByService'] as $service => $requests)
                <div style="margin-bottom: 6px;">
                    <strong>{{ $service }}</strong>
                    <span style="color: #666;"> — {{ count($requests) }} request(s)</span>
                </div>
            @endforeach
        </div>
    </div>
    @endif

    {{-- Cookies --}}
    @if(!empty($auditData['cookies']))
    <div class="section">
        <div class="section-title">Cookies ({{ count($auditData['cookies']) }})</div>
        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Domain</th>
                    <th style="width: 60px;">Secure</th>
                    <th style="width: 60px;">HttpOnly</th>
                </tr>
            </thead>
            <tbody>
                @foreach(array_slice($auditData['cookies'], 0, 30) as $cookie)
                <tr>
                    <td><strong>{{ $cookie['name'] ?? '' }}</strong></td>
                    <td>{{ $cookie['domain'] ?? '' }}</td>
                    <td>{{ ($cookie['secure'] ?? false) ? 'Yes' : 'No' }}</td>
                    <td>{{ ($cookie['httpOnly'] ?? false) ? 'Yes' : 'No' }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @if(count($auditData['cookies']) > 30)
            <div style="text-align: center; color: #999; margin-top: 6px; font-size: 10px;">
                ... and {{ count($auditData['cookies']) - 30 }} more cookies
            </div>
        @endif
    </div>
    @endif

    {{-- Footer --}}
    <div class="footer">
        Generated on {{ $generatedAt }} • {{ $auditMode === 'full' ? 'Full' : 'Quick' }} Audit
        @if($auditData['aiEnhanced'] ?? false) • AI-Enhanced @endif
        • LSM - Landeseiten Maintenance
    </div>
</body>

</html>
