<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Accessibility Audit Report - {{ $projectName }}</title>
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
            padding-bottom: 20px;
            margin-bottom: 24px;
            border-bottom: 3px solid #0891b2;
        }
        .header h1 {
            font-size: 24px;
            font-weight: 800;
            color: #1a1d27;
            letter-spacing: -0.5px;
            margin-bottom: 4px;
        }
        .header .subtitle {
            font-size: 13px;
            color: #8890a4;
            margin-bottom: 12px;
        }
        .header .url {
            font-size: 11px;
            color: #0891b2;
            text-decoration: none;
        }
        .meta-row {
            display: table;
            width: 100%;
            margin-top: 14px;
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
            margin-top: 2px;
        }
        .meta-value {
            font-size: 12px;
            font-weight: 600;
            color: #1a1d27;
        }

        /* ── Score Section ────────────────────────────────── */
        .score-section {
            text-align: center;
            margin: 20px 0 24px;
            padding: 20px;
            background: #f8f9fb;
            border: 1px solid #dfe2e8;
            border-radius: 8px;
        }
        .score-row {
            display: table;
            width: 100%;
        }
        .score-main {
            display: table-cell;
            width: 30%;
            text-align: center;
            vertical-align: middle;
        }
        .score-circle {
            display: inline-block;
            width: 80px;
            height: 80px;
            line-height: 80px;
            border-radius: 50%;
            font-size: 28px;
            font-weight: 800;
            color: white;
            text-align: center;
        }
        .score-good { background: #16a34a; }
        .score-warning { background: #d97706; }
        .score-bad { background: #dc2626; }
        .score-label {
            display: block;
            font-size: 10px;
            color: #8890a4;
            margin-top: 6px;
        }
        .score-stats {
            display: table-cell;
            width: 70%;
            vertical-align: middle;
        }
        .stats-grid {
            display: table;
            width: 100%;
        }
        .stat-box {
            display: table-cell;
            text-align: center;
            padding: 8px 6px;
            vertical-align: top;
        }
        .stat-value {
            font-size: 20px;
            font-weight: 700;
            display: block;
        }
        .stat-value-good { color: #16a34a; }
        .stat-value-bad { color: #dc2626; }
        .stat-value-neutral { color: #1a1d27; }
        .stat-label {
            font-size: 9px;
            color: #8890a4;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: block;
            margin-top: 2px;
        }

        /* ── Status Badge ─────────────────────────────────── */
        .status-badge {
            display: inline-block;
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            margin-top: 8px;
        }
        .status-pass { background: #f0fdf4; color: #16a34a; border: 1px solid #bbf7d0; }
        .status-warning { background: #fffbeb; color: #d97706; border: 1px solid #fde68a; }
        .status-critical { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }

        /* ── Section ──────────────────────────────────────── */
        .section {
            margin-bottom: 18px;
            page-break-inside: avoid;
        }
        .section-header {
            display: table;
            width: 100%;
            margin-bottom: 8px;
            padding-bottom: 6px;
            border-bottom: 2px solid #dfe2e8;
        }
        .section-icon {
            display: table-cell;
            width: 20px;
            vertical-align: middle;
            font-size: 14px;
        }
        .section-title {
            display: table-cell;
            vertical-align: middle;
            font-size: 14px;
            font-weight: 700;
            color: #1a1d27;
            letter-spacing: -0.3px;
        }

        /* ── Verdict Bar ──────────────────────────────────── */
        .verdict {
            padding: 10px 14px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 500;
            margin-bottom: 10px;
            line-height: 1.6;
        }
        .verdict-pass { background: #f0fdf4; border: 1px solid #bbf7d0; color: #15803d; }
        .verdict-warning { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; }
        .verdict-fail { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }

        /* ── Tables ───────────────────────────────────────── */
        .audit-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 10px;
            margin-bottom: 8px;
            border: 1px solid #dfe2e8;
        }
        .audit-table th {
            text-align: left;
            font-size: 9px;
            font-weight: 700;
            color: #8890a4;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            padding: 8px 12px;
            background: #f8f9fb;
            border-bottom: 2px solid #dfe2e8;
        }
        .audit-table td {
            padding: 7px 12px;
            border-bottom: 1px solid #f1f3f6;
            vertical-align: top;
            color: #4a5068;
        }
        .audit-table tr:last-child td { border-bottom: none; }
        .audit-table td:first-child { font-weight: 500; color: #1a1d27; }

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
        .tag-serious { background: #fff7ed; color: #c2410c; border: 1px solid #fed7aa; }
        .tag-moderate { background: #fffbeb; color: #d97706; border: 1px solid #fde68a; }
        .tag-minor { background: #f8f9fb; color: #8890a4; border: 1px solid #dfe2e8; }
        .tag-pass { background: #f0fdf4; color: #16a34a; border: 1px solid #bbf7d0; }
        .tag-high { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }
        .tag-medium { background: #fffbeb; color: #d97706; border: 1px solid #fde68a; }
        .tag-low { background: #f8f9fb; color: #8890a4; border: 1px solid #dfe2e8; }

        /* ── Critical Issues Box ──────────────────────────── */
        .critical-box {
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 8px;
            padding: 16px 20px;
            margin-bottom: 18px;
            page-break-inside: avoid;
        }
        .critical-box h3 {
            font-size: 13px;
            font-weight: 700;
            color: #dc2626;
            margin-bottom: 10px;
        }
        .critical-item {
            padding: 4px 0 4px 16px;
            position: relative;
            font-size: 11px;
            color: #1a1d27;
        }
        .critical-item::before {
            content: "•";
            color: #dc2626;
            position: absolute;
            left: 4px;
            font-weight: bold;
        }

        /* ── Recommendations ──────────────────────────────── */
        .rec-box {
            background: #f8f9fb;
            border: 1px solid #dfe2e8;
            border-radius: 8px;
            padding: 16px 20px;
            margin-bottom: 18px;
            page-break-inside: avoid;
        }
        .rec-box h3 {
            font-size: 13px;
            font-weight: 700;
            color: #1a1d27;
            margin-bottom: 12px;
        }
        .rec-item {
            display: table;
            width: 100%;
            padding: 6px 0;
            border-bottom: 1px solid #dfe2e8;
        }
        .rec-item:last-child { border-bottom: none; }
        .rec-number {
            display: table-cell;
            width: 24px;
            text-align: center;
            vertical-align: top;
            font-size: 10px;
            font-weight: 700;
            color: #0891b2;
            padding-top: 2px;
        }
        .rec-priority {
            display: table-cell;
            width: 60px;
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
            padding: 14px 18px;
            margin-bottom: 18px;
        }
        .positive-item {
            padding: 3px 0 3px 16px;
            position: relative;
            font-size: 11px;
            color: #15803d;
        }
        .positive-item::before {
            content: "✓";
            position: absolute;
            left: 0;
            color: #16a34a;
            font-weight: bold;
        }

        /* ── Badges ───────────────────────────────────────── */
        .ai-badge {
            display: inline-block;
            background: #0891b2;
            color: white;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 9px;
            font-weight: 700;
        }
        .mode-badge {
            display: inline-block;
            background: #f8f9fb;
            color: #4a5068;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 9px;
            font-weight: 600;
            border: 1px solid #dfe2e8;
        }

        .wcag-ref {
            font-family: 'DejaVu Sans Mono', monospace;
            font-size: 9px;
            background: #f1f3f6;
            padding: 1px 4px;
            border-radius: 3px;
            color: #4a5068;
        }

        /* ── Footer ───────────────────────────────────────── */
        .footer {
            margin-top: 28px;
            padding-top: 14px;
            border-top: 2px solid #dfe2e8;
            text-align: center;
            font-size: 9px;
            color: #8890a4;
        }
        .footer strong { color: #0891b2; }

        .page-break { page-break-before: always; }
    </style>
</head>

<body>
    {{-- ══ HEADER ══════════════════════════════════════════ --}}
    <div class="header">
        <h1>Accessibility Audit Report</h1>
        <div class="subtitle">{{ $projectName }} — WCAG 2.1 Level {{ $wcagLevel }} Compliance</div>
        @if($url)
            <a href="{{ $url }}" class="url">{{ $url }}</a>
        @endif
        <div class="meta-row" style="margin-top: 14px;">
            <div class="meta-item">
                <span class="meta-value">{{ $generatedAt }}</span>
                <span class="meta-label">Audit Date</span>
            </div>
            <div class="meta-item">
                <span class="meta-value">WCAG 2.1 {{ $wcagLevel }}</span>
                <span class="meta-label">Standard</span>
            </div>
            <div class="meta-item">
                @if($auditData['aiEnhanced'] ?? false)
                    <span class="ai-badge">AI-Enhanced</span>
                @else
                    <span class="mode-badge">Automated Scan</span>
                @endif
                <span class="meta-label">Analysis</span>
            </div>
        </div>
    </div>

    {{-- ══ SCORE SECTION ══════════════════════════════════ --}}
    <div class="score-section">
        <div class="score-row">
            <div class="score-main">
                <div class="score-circle {{ $score >= 80 ? 'score-good' : ($score >= 50 ? 'score-warning' : 'score-bad') }}">
                    {{ $score }}
                </div>
                <span class="score-label">Accessibility Score</span>
                <div style="margin-top: 8px;">
                    <span class="status-badge {{ $score >= 80 ? 'status-pass' : ($score >= 50 ? 'status-warning' : 'status-critical') }}">
                        {{ $verdict }}
                    </span>
                </div>
            </div>
            <div class="score-stats">
                <div class="stats-grid">
                    <div class="stat-box">
                        <span class="stat-value {{ ($auditData['summary']['totalViolations'] ?? 0) > 0 ? 'stat-value-bad' : 'stat-value-good' }}">
                            {{ $auditData['summary']['totalViolations'] ?? 0 }}
                        </span>
                        <span class="stat-label">Violations</span>
                    </div>
                    <div class="stat-box">
                        <span class="stat-value {{ ($auditData['summary']['impactCounts']['critical'] ?? 0) > 0 ? 'stat-value-bad' : 'stat-value-good' }}">
                            {{ $auditData['summary']['impactCounts']['critical'] ?? 0 }}
                        </span>
                        <span class="stat-label">Critical</span>
                    </div>
                    <div class="stat-box">
                        <span class="stat-value {{ ($auditData['summary']['impactCounts']['serious'] ?? 0) > 0 ? 'stat-value-bad' : 'stat-value-good' }}">
                            {{ $auditData['summary']['impactCounts']['serious'] ?? 0 }}
                        </span>
                        <span class="stat-label">Serious</span>
                    </div>
                    <div class="stat-box">
                        <span class="stat-value stat-value-good">
                            {{ $auditData['summary']['totalPasses'] ?? 0 }}
                        </span>
                        <span class="stat-label">Passed</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ══ AI SUMMARY ════════════════════════════════════ --}}
    @if(!empty($aiSummary['summary']))
    <div class="section">
        <div class="section-header">
            <span class="section-icon">🤖</span>
            <span class="section-title">AI Accessibility Analysis</span>
        </div>
        <div class="verdict {{ $score >= 80 ? 'verdict-pass' : ($score >= 50 ? 'verdict-warning' : 'verdict-fail') }}">
            {{ $aiSummary['summary'] }}
        </div>
    </div>
    @endif

    {{-- ══ CRITICAL ISSUES ════════════════════════════════ --}}
    @if(!empty($auditData['issues']))
    <div class="critical-box">
        <h3>Issues Summary ({{ count($auditData['issues']) }})</h3>
        @foreach($auditData['issues'] as $issue)
            <div class="critical-item">{{ $issue }}</div>
        @endforeach
    </div>
    @endif

    {{-- ══ VIOLATIONS ═════════════════════════════════════ --}}
    @if(!empty($aiSummary['violations']))
    <div class="section">
        <div class="section-header">
            <span class="section-icon">❌</span>
            <span class="section-title">Violations ({{ count($aiSummary['violations']) }})</span>
        </div>
        <table class="audit-table">
            <thead>
                <tr>
                    <th style="width: 60px;">Impact</th>
                    <th style="width: 140px;">Issue</th>
                    <th>Details</th>
                    <th style="width: 100px;">WCAG</th>
                    <th style="width: 150px;">Recommendation</th>
                </tr>
            </thead>
            <tbody>
                @foreach($aiSummary['violations'] as $v)
                <tr>
                    <td><span class="tag tag-{{ $v['severity'] ?? 'moderate' }}">{{ $v['severity'] ?? 'moderate' }}</span></td>
                    <td style="font-weight: 600; color: #1a1d27;">{{ $v['title'] ?? '' }}</td>
                    <td>{{ $v['description'] ?? '' }}</td>
                    <td><span class="wcag-ref">{{ $v['wcagRef'] ?? '' }}</span></td>
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
            <span class="section-icon">✅</span>
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
            <span class="section-icon">💡</span>
            <span class="section-title">Recommendations (by Priority)</span>
        </div>
        <div class="rec-box">
            @foreach($aiSummary['recommendations'] as $i => $r)
                <div class="rec-item">
                    <div class="rec-number">{{ $i + 1 }}</div>
                    <div class="rec-priority">
                        <span class="tag tag-{{ ($r['priority'] ?? '') === 'high' ? 'high' : (($r['priority'] ?? '') === 'medium' ? 'medium' : 'low') }}">
                            {{ $r['priority'] ?? 'low' }}
                        </span>
                    </div>
                    <div class="rec-text">{{ $r['action'] ?? '' }}</div>
                </div>
            @endforeach
        </div>
    </div>
    @endif

    {{-- ══ DETAILED VIOLATIONS (axe-core) ══════════════════ --}}
    @if(!empty($auditData['violations']))
    <div class="page-break"></div>
    <div class="section">
        <div class="section-header">
            <span class="section-icon">🔍</span>
            <span class="section-title">Detailed Violations ({{ count($auditData['violations']) }})</span>
        </div>
        <table class="audit-table">
            <thead>
                <tr>
                    <th style="width: 55px;">Impact</th>
                    <th style="width: 80px;">Category</th>
                    <th>Rule</th>
                    <th style="width: 80px;">WCAG</th>
                    <th style="width: 50px;">Elements</th>
                </tr>
            </thead>
            <tbody>
                @foreach(array_slice($auditData['violations'], 0, 30) as $v)
                <tr>
                    <td><span class="tag tag-{{ $v['impact'] ?? 'moderate' }}">{{ $v['impact'] ?? '' }}</span></td>
                    <td style="font-size: 10px;">{{ $v['category'] ?? '' }}</td>
                    <td>
                        <strong>{{ $v['help'] ?? $v['description'] ?? '' }}</strong>
                    </td>
                    <td>
                        @foreach(($v['wcag'] ?? []) as $wcag)
                            <span class="wcag-ref">{{ $wcag }}</span>
                        @endforeach
                    </td>
                    <td style="text-align: center;">{{ $v['nodeCount'] ?? 0 }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @if(count($auditData['violations']) > 30)
            <div style="text-align: center; color: #8890a4; margin-top: 6px; font-size: 10px;">
                … and {{ count($auditData['violations']) - 30 }} more violations
            </div>
        @endif
    </div>
    @endif

    {{-- ══ CUSTOM CHECKS ══════════════════════════════════ --}}
    @if(!empty($auditData['customChecks']))
    <div class="section">
        <div class="section-header">
            <span class="section-icon">⚙</span>
            <span class="section-title">Additional Checks</span>
        </div>
        <table class="audit-table">
            <thead>
                <tr>
                    <th style="width: 40px;"></th>
                    <th style="width: 150px;">Check</th>
                    <th>Details</th>
                    <th style="width: 60px;">WCAG</th>
                </tr>
            </thead>
            <tbody>
                @foreach($auditData['customChecks'] as $check)
                <tr>
                    <td style="text-align: center; font-size: 14px;">
                        @if(($check['status'] ?? '') === 'pass')
                            ✅
                        @elseif(($check['status'] ?? '') === 'warning')
                            ⚠️
                        @else
                            ❌
                        @endif
                    </td>
                    <td style="font-weight: 600; color: #1a1d27;">{{ $check['name'] ?? '' }}</td>
                    <td>{{ $check['description'] ?? '' }}</td>
                    <td><span class="wcag-ref">{{ $check['wcag'] ?? '' }}</span></td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif

    {{-- ══ FOOTER ═════════════════════════════════════════ --}}
    <div class="footer">
        <strong>LSM — Landeseiten Maintenance</strong><br>
        Generated on {{ $generatedAt }} · WCAG 2.1 Level {{ $wcagLevel }} Audit
        @if($auditData['aiEnhanced'] ?? false) · AI-Enhanced Analysis @endif
        <br>
        <span style="font-size: 8px; margin-top: 4px; display: block;">
            This automated accessibility audit checks against WCAG 2.1 Level {{ $wcagLevel }} using axe-core.
            Automated testing typically catches 30-40% of accessibility issues — manual testing is also recommended.
        </span>
    </div>

</body>
</html>
