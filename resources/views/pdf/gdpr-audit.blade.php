<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>GDPR Audit Report - {{ $projectName }}</title>
    <style>
        /* ── Reset & Base ─────────────────────────────────── */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 11px;
            line-height: 1.6;
            color: #1a1d27;
            padding: 28px 32px;
            background: #fff;
        }

        /* ── Header ───────────────────────────────────────── */
        .header {
            text-align: center;
            padding-bottom: 24px;
            margin-bottom: 28px;
            border-bottom: 2px solid #e8ebf0;
        }
        .header h1 {
            font-size: 26px;
            font-weight: 800;
            color: #1a1d27;
            letter-spacing: -0.5px;
            margin-bottom: 6px;
        }
        .header .subtitle {
            font-size: 14px;
            color: #8890a4;
            margin-bottom: 16px;
        }

        /* Status Badge */
        .status-badge-wrap { text-align: center; margin-bottom: 18px; }
        .status-badge {
            display: inline-block;
            padding: 8px 24px;
            border-radius: 24px;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }
        .status-pass { background: #f0fdf4; color: #16a34a; border: 2px solid #bbf7d0; }
        .status-warning { background: #fffbeb; color: #d97706; border: 2px solid #fde68a; }
        .status-critical { background: #fef2f2; color: #dc2626; border: 2px solid #fecaca; }

        .meta-row {
            display: table;
            width: 100%;
            margin-top: 16px;
        }
        .meta-item {
            display: table-cell;
            text-align: center;
            padding: 0 8px;
        }
        .meta-label {
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: #8890a4;
            font-weight: 600;
            display: block;
            margin-top: 3px;
        }
        .meta-value {
            font-size: 12px;
            font-weight: 600;
            color: #4a5068;
        }

        /* ── Score Section ────────────────────────────────── */
        .score-section {
            text-align: center;
            margin: 0 0 28px;
            padding: 24px;
            background: #f8f9fb;
            border: 1px solid #e8ebf0;
            border-radius: 10px;
        }
        .score-row {
            display: table;
            width: 100%;
        }
        .score-main {
            display: table-cell;
            width: 35%;
            text-align: center;
            vertical-align: middle;
        }
        .score-wheel {
            position: relative;
            display: inline-block;
            width: 120px;
            height: 120px;
        }
        .score-number {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 36px;
            font-weight: 800;
        }
        .score-label {
            display: block;
            font-size: 11px;
            color: #8890a4;
            margin-top: 8px;
        }
        .score-stats {
            display: table-cell;
            width: 65%;
            vertical-align: middle;
        }
        .stats-grid {
            display: table;
            width: 100%;
        }
        .stat-box {
            display: table-cell;
            text-align: center;
            padding: 8px 4px;
            vertical-align: top;
        }
        .stat-value {
            font-size: 22px;
            font-weight: 700;
            display: block;
        }
        .stat-label {
            font-size: 9px;
            color: #8890a4;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: block;
            margin-top: 3px;
        }
        .color-good { color: #16a34a; }
        .color-bad { color: #dc2626; }
        .color-warn { color: #d97706; }
        .color-neutral { color: #1a1d27; }

        /* ── Verification Box ─────────────────────────────── */
        .verify-box {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 8px;
            padding: 14px 18px;
            margin-bottom: 24px;
        }
        .verify-box strong {
            color: #2563eb;
            font-size: 12px;
        }
        .verify-box p {
            font-size: 11px;
            color: #4a5068;
            margin-top: 4px;
            line-height: 1.5;
        }

        /* ── Section ──────────────────────────────────────── */
        .section {
            margin-bottom: 20px;
            page-break-inside: avoid;
        }
        .section-header {
            margin-bottom: 10px;
            padding-bottom: 6px;
            border-bottom: 2px solid #e8ebf0;
        }
        .section-title {
            font-size: 15px;
            font-weight: 700;
            color: #1a1d27;
            letter-spacing: -0.3px;
        }
        .section-count {
            font-size: 12px;
            font-weight: 400;
            color: #8890a4;
        }

        /* ── Verdict Bar ──────────────────────────────────── */
        .verdict {
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 500;
            margin-bottom: 12px;
            line-height: 1.6;
        }
        .verdict-pass { background: #f0fdf4; border: 1px solid #bbf7d0; color: #15803d; }
        .verdict-warning { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; }
        .verdict-fail { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }

        /* ── Tables ───────────────────────────────────────── */
        .audit-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            font-size: 10px;
            margin-bottom: 8px;
            border: 1px solid #e8ebf0;
            border-radius: 8px;
            overflow: hidden;
        }
        .audit-table th {
            text-align: left;
            font-size: 9px;
            font-weight: 700;
            color: #8890a4;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            padding: 10px 14px;
            background: #f8f9fb;
            border-bottom: 2px solid #e8ebf0;
        }
        .audit-table td {
            padding: 8px 14px;
            border-bottom: 1px solid #f1f3f6;
            vertical-align: top;
            color: #4a5068;
        }
        .audit-table tr:last-child td { border-bottom: none; }
        .status-cell { text-align: center; width: 40px; font-weight: 700; font-size: 12px; }
        .status-pass { color: #16a34a; }
        .status-fail { color: #dc2626; }
        .status-warn { color: #d97706; }

        /* ── Tags ─────────────────────────────────────────── */
        .tag {
            display: inline-block;
            font-size: 9px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 4px;
            text-transform: uppercase;
        }
        .tag-critical { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }
        .tag-high { background: #fff7ed; color: #c2410c; border: 1px solid #fed7aa; }
        .tag-medium { background: #fffbeb; color: #d97706; border: 1px solid #fde68a; }
        .tag-low { background: #f8f9fb; color: #8890a4; border: 1px solid #e8ebf0; }
        .tag-pass { background: #f0fdf4; color: #16a34a; border: 1px solid #bbf7d0; }

        /* ── Critical Issues Box ──────────────────────────── */
        .critical-box {
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 8px;
            padding: 18px 22px;
            margin-bottom: 20px;
            page-break-inside: avoid;
        }
        .critical-box h3 {
            font-size: 14px;
            font-weight: 700;
            color: #dc2626;
            margin-bottom: 12px;
        }
        .critical-item {
            padding: 4px 0 4px 16px;
            position: relative;
            font-size: 11px;
            color: #1a1d27;
            line-height: 1.5;
        }
        .critical-item::before {
            content: "\2022";
            color: #dc2626;
            position: absolute;
            left: 4px;
            font-weight: bold;
        }

        /* ── Recommendations ──────────────────────────────── */
        .rec-box {
            background: #f8f9fb;
            border: 1px solid #e8ebf0;
            border-radius: 8px;
            padding: 18px 22px;
            margin-bottom: 20px;
            page-break-inside: avoid;
        }
        .rec-box h3 {
            font-size: 14px;
            font-weight: 700;
            color: #1a1d27;
            margin-bottom: 14px;
        }
        .rec-item {
            display: table;
            width: 100%;
            padding: 7px 0;
            border-bottom: 1px solid #e8ebf0;
        }
        .rec-item:last-child { border-bottom: none; }
        .rec-number {
            display: table-cell;
            width: 28px;
            text-align: center;
            vertical-align: top;
            font-size: 11px;
            font-weight: 700;
            color: #4f46e5;
            padding-top: 2px;
        }
        .rec-priority {
            display: table-cell;
            width: 65px;
            vertical-align: top;
            padding-top: 2px;
        }
        .rec-text {
            display: table-cell;
            vertical-align: top;
            font-size: 11px;
            color: #4a5068;
            line-height: 1.5;
        }

        /* ── Positives ────────────────────────────────────── */
        .positive-box {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            border-radius: 8px;
            padding: 16px 20px;
            margin-bottom: 20px;
        }
        .positive-item {
            padding: 3px 0 3px 16px;
            position: relative;
            font-size: 11px;
            color: #15803d;
            line-height: 1.5;
        }
        .positive-item::before {
            content: "\2713";
            position: absolute;
            left: 0;
            color: #16a34a;
            font-weight: bold;
        }

        /* ── Cookie Evidence ──────────────────────────────── */
        .cookie-list {
            background: #f8f9fb;
            border: 1px solid #e8ebf0;
            border-radius: 6px;
            padding: 12px 16px;
            margin: 8px 0;
            font-size: 10px;
            color: #4a5068;
            line-height: 1.8;
        }
        .cookie-name { color: #dc2626; font-weight: 600; }
        .cookie-service { color: #8890a4; }

        /* ── Small badges ─────────────────────────────────── */
        .ai-badge {
            display: inline-block;
            background: #4f46e5;
            color: white;
            padding: 3px 12px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 700;
        }
        .mode-badge {
            display: inline-block;
            background: #f8f9fb;
            color: #4a5068;
            padding: 3px 12px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
            border: 1px solid #e8ebf0;
        }

        .legal-ref {
            font-family: 'DejaVu Sans Mono', monospace;
            font-size: 9px;
            background: #f1f3f6;
            padding: 1px 5px;
            border-radius: 3px;
            color: #4a5068;
        }

        /* ── Footer ───────────────────────────────────────── */
        .footer {
            margin-top: 30px;
            padding-top: 16px;
            border-top: 2px solid #e8ebf0;
            text-align: center;
            font-size: 10px;
            color: #8890a4;
        }
        .footer strong { color: #4f46e5; }

        .page-break { page-break-before: always; }
    </style>
</head>

<body>
    {{-- ══ HEADER ══════════════════════════════════════════ --}}
    <div class="header">
        <h1>GDPR Compliance Report</h1>
        <div class="subtitle">{{ $projectName }} &mdash; German Market</div>

        <div class="status-badge-wrap">
            <span class="status-badge {{ $score >= 80 ? 'status-pass' : ($score >= 50 ? 'status-warning' : 'status-critical') }}">
                @if($score >= 80)
                    COMPLIANT
                @elseif($score >= 50)
                    ISSUES FOUND &mdash; ACTION REQUIRED
                @else
                    CRITICAL VIOLATIONS
                @endif
            </span>
        </div>

        <div class="meta-row">
            <div class="meta-item">
                <span class="meta-value">{{ $generatedAt }}</span>
                <span class="meta-label">Date</span>
            </div>
            <div class="meta-item">
                @if(!empty($auditData['cookieBanner']['solution']))
                    <span class="meta-value">{{ $auditData['cookieBanner']['solution'] }}</span>
                @elseif(!empty($auditData['summary']['cookieBannerSolution']))
                    <span class="meta-value">{{ implode(', ', $auditData['summary']['cookieBannerSolution']) }}</span>
                @else
                    <span class="meta-value">&mdash;</span>
                @endif
                <span class="meta-label">Consent Tool</span>
            </div>
            <div class="meta-item">
                <span class="meta-value">{{ ucfirst($auditMode) }} Scan</span>
                <span class="meta-label">Mode</span>
            </div>
        </div>
    </div>

    {{-- ══ SCORE SECTION ══════════════════════════════════ --}}
    @php
        $circumference = 326.7;
        $dashoffset = $circumference - ($circumference * $score / 100);
        $scoreColor = $score >= 80 ? '#16a34a' : ($score >= 50 ? '#d97706' : '#dc2626');
    @endphp
    <div class="score-section">
        <div class="score-row">
            <div class="score-main">
                <div class="score-wheel">
                    <svg width="120" height="120" viewBox="0 0 120 120">
                        <circle cx="60" cy="60" r="52" fill="none" stroke="#e8ebf0" stroke-width="8"/>
                        <circle cx="60" cy="60" r="52" fill="none"
                            stroke="{{ $scoreColor }}"
                            stroke-width="8"
                            stroke-linecap="round"
                            stroke-dasharray="{{ $circumference }}"
                            stroke-dashoffset="{{ $dashoffset }}"
                            transform="rotate(-90 60 60)"/>
                    </svg>
                    <div class="score-number" style="color: {{ $scoreColor }};">{{ $score }}</div>
                </div>
                <span class="score-label">Compliance Score (of 100)</span>
            </div>
            <div class="score-stats">
                <div class="stats-grid">
                    <div class="stat-box">
                        <span class="stat-value {{ ($auditData['summary']['trackingRequests'] ?? 0) > 0 ? 'color-bad' : 'color-good' }}">
                            {{ $auditData['summary']['trackingRequests'] ?? 0 }}
                        </span>
                        <span class="stat-label">Trackers</span>
                    </div>
                    <div class="stat-box">
                        <span class="stat-value {{ ($auditData['summary']['trackingCookies'] ?? 0) > 0 ? 'color-bad' : 'color-good' }}">
                            {{ $auditData['summary']['trackingCookies'] ?? 0 }}
                        </span>
                        <span class="stat-label">Cookies</span>
                    </div>
                    <div class="stat-box">
                        <span class="stat-value {{ ($auditData['summary']['cookieBannerDetected'] ?? false) ? 'color-good' : 'color-bad' }}">
                            {{ ($auditData['summary']['cookieBannerDetected'] ?? false) ? 'Yes' : 'No' }}
                        </span>
                        <span class="stat-label">Banner</span>
                    </div>
                    @if(($auditData['summary']['acceptFlowWorks'] ?? null) !== null)
                    <div class="stat-box">
                        <span class="stat-value {{ ($auditData['summary']['acceptFlowWorks'] ?? false) ? 'color-good' : 'color-bad' }}">
                            {{ ($auditData['summary']['acceptFlowWorks'] ?? false) ? 'Yes' : 'No' }}
                        </span>
                        <span class="stat-label">Accept</span>
                    </div>
                    @endif
                    @if(($auditData['summary']['rejectFlowClean'] ?? null) !== null)
                    <div class="stat-box">
                        <span class="stat-value {{ ($auditData['summary']['rejectFlowClean'] ?? false) ? 'color-good' : 'color-bad' }}">
                            {{ ($auditData['summary']['rejectFlowClean'] ?? false) ? 'Yes' : 'No' }}
                        </span>
                        <span class="stat-label">Reject</span>
                    </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- ══ VERIFICATION BOX ══════════════════════════════ --}}
    <div class="verify-box">
        <strong>Verification Method</strong>
        <p>This audit was performed using Puppeteer with fresh browser contexts (equivalent to incognito mode) for each scenario, ensuring zero session contamination and reproducible results.</p>
    </div>

    {{-- ══ AI SUMMARY ════════════════════════════════════ --}}
    @if(!empty($aiSummary['summary']))
    <div class="section">
        <div class="section-header">
            <span class="section-title">AI Compliance Analysis</span>
        </div>
        <div class="verdict {{ $score >= 80 ? 'verdict-pass' : ($score >= 50 ? 'verdict-warning' : 'verdict-fail') }}">
            {{ $aiSummary['summary'] }}
        </div>
    </div>
    @endif

    {{-- ══ CRITICAL ISSUES ════════════════════════════════ --}}
    @if(!empty($auditData['issues']))
    <div class="critical-box">
        <h3>Critical Issues ({{ count($auditData['issues']) }})</h3>
        @foreach($auditData['issues'] as $issue)
            <div class="critical-item">{{ $issue }}</div>
        @endforeach
    </div>
    @endif

    {{-- ══ VIOLATIONS ═════════════════════════════════════ --}}
    @if(!empty($aiSummary['violations']))
    <div class="section">
        <div class="section-header">
            <span class="section-title">Violations <span class="section-count">({{ count($aiSummary['violations']) }})</span></span>
        </div>
        <table class="audit-table">
            <thead>
                <tr>
                    <th style="width: 60px;">Severity</th>
                    <th style="width: 130px;">Issue</th>
                    <th>Details</th>
                    <th style="width: 100px;">Legal Ref</th>
                    <th style="width: 140px;">Recommendation</th>
                </tr>
            </thead>
            <tbody>
                @foreach($aiSummary['violations'] as $v)
                <tr>
                    <td><span class="tag tag-{{ $v['severity'] ?? 'medium' }}">{{ $v['severity'] ?? 'medium' }}</span></td>
                    <td style="font-weight: 600; color: #1a1d27;">{{ $v['title'] ?? '' }}</td>
                    <td>{{ $v['description'] ?? '' }}</td>
                    <td><span class="legal-ref">{{ $v['legalRef'] ?? '' }}</span></td>
                    <td>{{ $v['recommendation'] ?? '' }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif

    {{-- ══ WHAT'S DONE RIGHT ══════════════════════════════ --}}
    @if(!empty($aiSummary['positives']))
    <div class="section">
        <div class="section-header">
            <span class="section-title">What's Done Right</span>
        </div>
        <div class="positive-box">
            @foreach($aiSummary['positives'] as $p)
                <div class="positive-item">{{ $p }}</div>
            @endforeach
        </div>
    </div>
    @endif

    {{-- ══ RECOMMENDATIONS ════════════════════════════════ --}}
    @if(!empty($aiSummary['recommendations']))
    <div class="section">
        <div class="section-header">
            <span class="section-title">Recommendations <span class="section-count">(by Priority)</span></span>
        </div>
        <div class="rec-box">
            @foreach($aiSummary['recommendations'] as $i => $r)
                <div class="rec-item">
                    <div class="rec-number">{{ $i + 1 }}.</div>
                    <div class="rec-priority">
                        <span class="tag tag-{{ ($r['priority'] ?? '') === 'high' ? 'critical' : (($r['priority'] ?? '') === 'medium' ? 'medium' : 'low') }}">
                            {{ $r['priority'] ?? 'low' }}
                        </span>
                    </div>
                    <div class="rec-text">{{ $r['action'] ?? '' }}</div>
                </div>
            @endforeach
        </div>
    </div>
    @endif

    <div class="page-break"></div>

    {{-- ══ AUDIT CHECKS ═══════════════════════════════════ --}}
    @if(!empty($auditData['checks']))
    <div class="section">
        <div class="section-header">
            <span class="section-title">Audit Checks</span>
        </div>
        <table class="audit-table">
            <thead>
                <tr>
                    <th style="width: 40px;"></th>
                    <th style="width: 180px;">Check</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
                @foreach($auditData['checks'] as $check)
                <tr>
                    <td class="status-cell {{ ($check['status'] ?? '') === 'pass' ? 'status-pass' : (($check['status'] ?? '') === 'warning' ? 'status-warn' : 'status-fail') }}">
                        @if(($check['status'] ?? '') === 'pass')
                            PASS
                        @elseif(($check['status'] ?? '') === 'warning')
                            WARN
                        @else
                            FAIL
                        @endif
                    </td>
                    <td style="font-weight: 600; color: #1a1d27;">{{ $check['name'] ?? '' }}</td>
                    <td>{{ $check['details'] ?? $check['detail'] ?? '' }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif

    {{-- ══ TRACKING SERVICES ═════════════════════════════ --}}
    @if(!empty($auditData['trackingByService']))
    <div class="section">
        <div class="section-header">
            <span class="section-title">Tracking Services Detected <span class="section-count">({{ count($auditData['trackingByService']) }})</span></span>
        </div>
        <table class="audit-table">
            <thead>
                <tr>
                    <th>Service</th>
                    <th style="width: 70px;">Severity</th>
                    <th style="width: 60px;">Requests</th>
                    <th>Example URL</th>
                </tr>
            </thead>
            <tbody>
                @foreach($auditData['trackingByService'] as $service => $info)
                <tr>
                    <td style="font-weight: 600; color: #1a1d27;">{{ $service }}</td>
                    <td><span class="tag tag-{{ ($info['severity'] ?? '') === 'critical' ? 'critical' : 'medium' }}">{{ $info['severity'] ?? 'warning' }}</span></td>
                    <td style="text-align: center; font-weight: 600;">{{ $info['count'] ?? (is_array($info) ? count($info) : 0) }}</td>
                    <td style="font-size: 9px; word-break: break-all; color: #8890a4;">
                        @if(!empty($info['urls'][0]))
                            {{ \Illuminate\Support\Str::limit($info['urls'][0], 80) }}
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif

    {{-- ══ COOKIES ════════════════════════════════════════ --}}
    @if(!empty($auditData['cookies']))
    <div class="section">
        <div class="section-header">
            <span class="section-title">Cookies <span class="section-count">({{ count($auditData['cookies']) }})</span></span>
        </div>

        @php
            $trackingCookies = array_filter($auditData['cookies'], fn($c) => ($c['classification']['type'] ?? '') === 'tracking');
        @endphp

        @if(count($trackingCookies) > 0)
        <div class="cookie-list">
            <strong style="color: #dc2626;">Tracking Cookies ({{ count($trackingCookies) }}):</strong><br>
            @foreach($trackingCookies as $cookie)
                <span class="cookie-name">{{ $cookie['name'] ?? '' }}</span>
                <span class="cookie-service">({{ $cookie['classification']['service'] ?? '' }} | {{ $cookie['domain'] ?? '' }})</span><br>
            @endforeach
        </div>
        @endif

        <table class="audit-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Domain</th>
                    <th style="width: 50px;">Type</th>
                    <th style="width: 50px;">Secure</th>
                    <th style="width: 60px;">HttpOnly</th>
                </tr>
            </thead>
            <tbody>
                @foreach(array_slice($auditData['cookies'], 0, 25) as $cookie)
                <tr>
                    <td style="font-weight: 600; font-size: 10px;">{{ $cookie['name'] ?? '' }}</td>
                    <td style="font-size: 10px; color: #8890a4;">{{ $cookie['domain'] ?? '' }}</td>
                    <td>
                        @if(($cookie['classification']['type'] ?? '') === 'tracking')
                            <span class="tag tag-critical">TRACK</span>
                        @else
                            <span class="tag tag-low">OTHER</span>
                        @endif
                    </td>
                    <td class="status-cell {{ ($cookie['secure'] ?? false) ? 'status-pass' : 'status-fail' }}">
                        {{ ($cookie['secure'] ?? false) ? 'YES' : 'NO' }}
                    </td>
                    <td class="status-cell {{ ($cookie['httpOnly'] ?? false) ? 'status-pass' : 'status-fail' }}">
                        {{ ($cookie['httpOnly'] ?? false) ? 'YES' : 'NO' }}
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @if(count($auditData['cookies']) > 25)
            <div style="text-align: center; color: #8890a4; margin-top: 6px; font-size: 10px;">
                ... and {{ count($auditData['cookies']) - 25 }} more cookies
            </div>
        @endif
    </div>
    @endif

    {{-- ══ CONSENT FLOWS ═════════════════════════════════ --}}
    @if(!empty($auditData['scenarios']))
    <div class="section">
        <div class="section-header">
            <span class="section-title">Consent Flow Analysis</span>
        </div>

        @if(!empty($auditData['scenarios']['acceptAll']))
        <div style="margin-bottom: 12px;">
            <strong style="color: #1a1d27;">Accept-All Flow:</strong>
            @if($auditData['scenarios']['acceptAll']['clicked'] ?? false)
                Clicked "{{ $auditData['scenarios']['acceptAll']['clicked'] }}" &mdash;
                @if(count($auditData['scenarios']['acceptAll']['postTracking'] ?? []) > 0)
                    <span class="tag tag-pass">{{ count($auditData['scenarios']['acceptAll']['postTracking']) }} tracking request(s) after accept - CMP working</span>
                @else
                    <span class="tag tag-medium">No activity &mdash; CMP may not be working</span>
                @endif
            @else
                <span class="tag tag-medium">Could not find Accept button</span>
            @endif
        </div>
        @endif

        @if(!empty($auditData['scenarios']['reject']))
        <div>
            <strong style="color: #1a1d27;">Reject Flow:</strong>
            @if($auditData['scenarios']['reject']['clicked'] ?? false)
                Clicked "{{ $auditData['scenarios']['reject']['clicked'] }}" &mdash;
                @if(count($auditData['scenarios']['reject']['postTracking'] ?? []) === 0)
                    <span class="tag tag-pass">Clean &mdash; no tracking after rejection</span>
                @else
                    <span class="tag tag-critical">{{ count($auditData['scenarios']['reject']['postTracking']) }} tracking request(s) AFTER rejection!</span>
                    <div class="cookie-list" style="margin-top: 8px;">
                        @foreach($auditData['scenarios']['reject']['postTracking'] as $r)
                            <span class="cookie-name">{{ implode(', ', $r['labels'] ?? []) }}</span>
                            <span class="cookie-service">{{ \Illuminate\Support\Str::limit($r['url'] ?? '', 80) }}</span><br>
                        @endforeach
                    </div>
                @endif
            @else
                <span class="tag tag-medium">Could not find Reject button</span>
            @endif
        </div>
        @endif
    </div>
    @endif

    {{-- ══ FOOTER ═════════════════════════════════════════ --}}
    <div class="footer">
        <strong>LSM &mdash; Landeseiten Maintenance</strong><br>
        Generated on {{ $generatedAt }} | {{ ucfirst($auditMode) }} Audit
        @if($auditData['aiEnhanced'] ?? false) | AI-Enhanced Analysis @endif
        <br>
        <span style="font-size: 8px; margin-top: 4px; display: block;">
            This audit is a point-in-time snapshot and does not constitute legal advice.
        </span>
    </div>
</body>
</html>
