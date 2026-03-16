/**
 * GDPR Audit Report — HTML Template
 * 
 * Generates a premium HTML report for GDPR compliance audits.
 * Rendered to PDF by Puppeteer (full Chrome rendering).
 * 
 * @param {object} data — Audit data from the Laravel controller
 * @returns {string} Full HTML document
 */

module.exports = function gdprAuditTemplate(data) {
  const {
    projectName = 'Unknown Project',
    url = '',
    auditData = {},
    aiSummary = {},
    score = 0,
    verdict = 'Unknown',
    auditMode = 'quick',
    generatedAt = new Date().toLocaleString(),
  } = data;

  const summary = auditData.summary || {};
  const trackingRequests = summary.trackingRequests || 0;
  const trackingCookies = summary.trackingCookies || 0;
  const bannerDetected = summary.cookieBannerDetected || false;
  const acceptFlowWorks = summary.acceptFlowWorks;
  const rejectFlowClean = summary.rejectFlowClean;
  const checks = auditData.checks || [];
  const violations = aiSummary.violations || [];
  const positives = aiSummary.positives || [];
  const recommendations = aiSummary.recommendations || [];
  const trackingByService = auditData.trackingByService || {};
  const cookies = auditData.cookies || [];
  const issues = auditData.issues || [];
  const scenarios = auditData.scenarios || {};
  const aiEnhanced = auditData.aiEnhanced || false;

  const consentTool = auditData.cookieBanner?.solution
    || (summary.cookieBannerSolution || []).join(', ')
    || '—';

  // Score color
  const scoreColor = score >= 80 ? '#16a34a' : score >= 50 ? '#d97706' : '#dc2626';
  const scoreColorLight = score >= 80 ? '#f0fdf4' : score >= 50 ? '#fffbeb' : '#fef2f2';
  const scoreColorBorder = score >= 80 ? '#bbf7d0' : score >= 50 ? '#fde68a' : '#fecaca';
  const verdictClass = score >= 80 ? 'pass' : score >= 50 ? 'warning' : 'critical';

  // SVG Score Ring
  const circumference = 339.292;
  const dashoffset = circumference - (circumference * score / 100);

  // Severity tag
  function severityTag(severity) {
    const colors = {
      critical: { bg: '#fef2f2', text: '#dc2626', border: '#fecaca' },
      high: { bg: '#fff7ed', text: '#c2410c', border: '#fed7aa' },
      medium: { bg: '#fffbeb', text: '#d97706', border: '#fde68a' },
      low: { bg: '#f8fafc', text: '#64748b', border: '#e2e8f0' },
    };
    const c = colors[severity] || colors.medium;
    return `<span style="display:inline-block;font-size:10px;font-weight:700;padding:2px 10px;border-radius:4px;text-transform:uppercase;background:${c.bg};color:${c.text};border:1px solid ${c.border};">${severity}</span>`;
  }

  function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  function truncate(str, len = 90) {
    if (!str) return '';
    return str.length > len ? str.substring(0, len) + '…' : str;
  }

  // Split cookies
  const trackingCookieList = cookies.filter(c => (c.classification?.type) === 'tracking');

  return `<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>GDPR Audit — ${escapeHtml(projectName)}</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
  /* ── Reset ─────────────────────────────────── */
  *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
  body {
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    font-size: 11px;
    line-height: 1.65;
    color: #1e293b;
    background: #fff;
    -webkit-font-smoothing: antialiased;
  }

  /* ── Header ────────────────────────────────── */
  .header {
    background: linear-gradient(135deg, #1e1b4b 0%, #312e81 40%, #4338ca 100%);
    color: #fff;
    padding: 40px 44px 36px;
    margin: -20mm -15mm 0 -15mm;
    position: relative;
  }
  .header::after {
    content: '';
    position: absolute;
    bottom: 0; left: 0; right: 0;
    height: 4px;
    background: linear-gradient(90deg, #a78bfa, #818cf8, #6366f1);
  }
  .header h1 {
    font-size: 28px;
    font-weight: 800;
    letter-spacing: -0.5px;
    margin-bottom: 4px;
  }
  .header .subtitle {
    font-size: 14px;
    color: rgba(255,255,255,0.7);
    font-weight: 400;
    margin-bottom: 20px;
  }
  .header-meta {
    display: flex;
    gap: 32px;
    margin-top: 16px;
  }
  .header-meta-item .label {
    font-size: 9px;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: rgba(255,255,255,0.5);
    font-weight: 600;
  }
  .header-meta-item .value {
    font-size: 13px;
    font-weight: 600;
    color: #fff;
    margin-top: 2px;
  }
  .status-badge {
    display: inline-block;
    padding: 6px 20px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.8px;
    text-transform: uppercase;
  }
  .status-pass { background: rgba(34,197,94,0.2); color: #86efac; border: 1px solid rgba(34,197,94,0.3); }
  .status-warning { background: rgba(234,179,8,0.2); color: #fde68a; border: 1px solid rgba(234,179,8,0.3); }
  .status-critical { background: rgba(239,68,68,0.2); color: #fca5a5; border: 1px solid rgba(239,68,68,0.3); }

  /* ── Score Section ─────────────────────────── */
  .score-section {
    display: flex;
    align-items: center;
    gap: 36px;
    padding: 28px 32px;
    margin: 28px 0;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
  }
  .score-ring {
    position: relative;
    width: 130px;
    height: 130px;
    flex-shrink: 0;
  }
  .score-ring svg { width: 130px; height: 130px; }
  .score-number {
    position: absolute;
    top: 50%; left: 50%;
    transform: translate(-50%, -50%);
    font-size: 40px;
    font-weight: 900;
    letter-spacing: -1px;
  }
  .score-label {
    text-align: center;
    font-size: 10px;
    color: #94a3b8;
    margin-top: 6px;
    font-weight: 500;
  }
  .stats-grid {
    display: flex;
    gap: 8px;
    flex: 1;
    flex-wrap: wrap;
  }
  .stat-box {
    text-align: center;
    padding: 12px 16px;
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    min-width: 80px;
    flex: 1;
  }
  .stat-value {
    font-size: 22px;
    font-weight: 800;
    display: block;
    line-height: 1.1;
  }
  .stat-label {
    font-size: 9px;
    color: #94a3b8;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-weight: 600;
    margin-top: 4px;
    display: block;
  }
  .color-good { color: #16a34a; }
  .color-bad { color: #dc2626; }
  .color-warn { color: #d97706; }

  /* ── Section ───────────────────────────────── */
  .section { margin-bottom: 24px; page-break-inside: avoid; }
  .section-header {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 12px;
    padding-bottom: 8px;
    border-bottom: 2px solid #e2e8f0;
  }
  .section-title {
    font-size: 16px;
    font-weight: 700;
    color: #0f172a;
    letter-spacing: -0.3px;
  }
  .section-count { font-size: 13px; color: #94a3b8; font-weight: 400; }

  /* ── Verdict Box ───────────────────────────── */
  .verdict-box {
    padding: 16px 20px;
    border-radius: 10px;
    font-size: 12px;
    font-weight: 500;
    line-height: 1.7;
    margin-bottom: 16px;
  }
  .verdict-pass { background: #f0fdf4; border: 1px solid #bbf7d0; color: #15803d; }
  .verdict-warning { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; }
  .verdict-critical { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }

  /* ── Verify Box ────────────────────────────── */
  .verify-box {
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    border-radius: 10px;
    padding: 14px 20px;
    margin-bottom: 24px;
  }
  .verify-box strong { color: #2563eb; font-size: 12px; }
  .verify-box p { color: #475569; margin-top: 4px; font-size: 11px; }

  /* ── Table ─────────────────────────────────── */
  .data-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    font-size: 11px;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    overflow: hidden;
    margin-bottom: 8px;
  }
  .data-table th {
    text-align: left;
    font-size: 9px;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    padding: 10px 16px;
    background: #f8fafc;
    border-bottom: 2px solid #e2e8f0;
  }
  .data-table td {
    padding: 10px 16px;
    border-bottom: 1px solid #f1f5f9;
    vertical-align: top;
    color: #475569;
  }
  .data-table tr:last-child td { border-bottom: none; }
  .data-table .cell-bold { font-weight: 600; color: #1e293b; }

  /* ── Check status ──────────────────────────── */
  .check-pass {
    display: inline-block;
    background: #f0fdf4;
    color: #16a34a;
    font-weight: 700;
    font-size: 10px;
    padding: 3px 10px;
    border-radius: 4px;
    border: 1px solid #bbf7d0;
  }
  .check-fail {
    display: inline-block;
    background: #fef2f2;
    color: #dc2626;
    font-weight: 700;
    font-size: 10px;
    padding: 3px 10px;
    border-radius: 4px;
    border: 1px solid #fecaca;
  }
  .check-warn {
    display: inline-block;
    background: #fffbeb;
    color: #d97706;
    font-weight: 700;
    font-size: 10px;
    padding: 3px 10px;
    border-radius: 4px;
    border: 1px solid #fde68a;
  }

  /* ── Critical Issues Box ───────────────────── */
  .issues-box {
    background: #fef2f2;
    border: 1px solid #fecaca;
    border-radius: 10px;
    padding: 20px 24px;
    margin-bottom: 20px;
  }
  .issues-box h3 { font-size: 14px; font-weight: 700; color: #dc2626; margin-bottom: 12px; }
  .issue-item {
    padding: 4px 0 4px 18px;
    position: relative;
    font-size: 11px;
    color: #1e293b;
    line-height: 1.6;
  }
  .issue-item::before {
    content: "•";
    color: #dc2626;
    position: absolute;
    left: 4px;
    font-weight: bold;
    font-size: 14px;
  }

  /* ── Positives ─────────────────────────────── */
  .positives-box {
    background: #f0fdf4;
    border: 1px solid #bbf7d0;
    border-radius: 10px;
    padding: 18px 22px;
    margin-bottom: 20px;
  }
  .positive-item {
    padding: 3px 0 3px 20px;
    position: relative;
    font-size: 11px;
    color: #15803d;
    line-height: 1.6;
  }
  .positive-item::before {
    content: "✓";
    position: absolute;
    left: 2px;
    color: #16a34a;
    font-weight: bold;
  }

  /* ── Recommendations ───────────────────────── */
  .rec-box {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 20px 24px;
    margin-bottom: 20px;
  }
  .rec-item {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 8px 0;
    border-bottom: 1px solid #e2e8f0;
  }
  .rec-item:last-child { border-bottom: none; }
  .rec-number {
    font-size: 12px;
    font-weight: 800;
    color: #6366f1;
    min-width: 24px;
  }
  .rec-text { font-size: 11px; color: #475569; line-height: 1.6; flex: 1; }

  /* ── Legal ref badge ───────────────────────── */
  .legal-ref {
    font-family: 'Inter', monospace;
    font-size: 9px;
    background: #f1f5f9;
    padding: 2px 6px;
    border-radius: 3px;
    color: #475569;
    font-weight: 500;
  }

  /* ── Cookie evidence ───────────────────────── */
  .cookie-evidence {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 14px 18px;
    margin: 8px 0;
    font-size: 10px;
    color: #475569;
    line-height: 1.9;
  }
  .cookie-name { color: #dc2626; font-weight: 600; }
  .cookie-service { color: #94a3b8; }

  /* ── Footer ────────────────────────────────── */
  .footer {
    margin-top: 36px;
    padding-top: 20px;
    border-top: 2px solid #e2e8f0;
    text-align: center;
    font-size: 10px;
    color: #94a3b8;
  }
  .footer strong { color: #6366f1; }
  .footer .disclaimer {
    font-size: 8px;
    margin-top: 6px;
    color: #cbd5e1;
  }

  /* ── Page break ────────────────────────────── */
  .page-break { page-break-before: always; }

  /* ── Consent flow ──────────────────────────── */
  .flow-section { margin-bottom: 12px; }
  .flow-label { font-weight: 600; color: #1e293b; font-size: 12px; }
</style>
</head>
<body>

<!-- ═══ HEADER ═══════════════════════════════════ -->
<div class="header">
  <h1>GDPR Compliance Report</h1>
  <div class="subtitle">${escapeHtml(projectName)} — ${escapeHtml(url)}</div>
  <span class="status-badge status-${verdictClass}">
    ${score >= 80 ? 'COMPLIANT' : score >= 50 ? 'ISSUES FOUND — ACTION REQUIRED' : 'CRITICAL VIOLATIONS'}
  </span>
  <div class="header-meta">
    <div class="header-meta-item">
      <div class="label">Date</div>
      <div class="value">${escapeHtml(generatedAt)}</div>
    </div>
    <div class="header-meta-item">
      <div class="label">Consent Tool</div>
      <div class="value">${escapeHtml(consentTool)}</div>
    </div>
    <div class="header-meta-item">
      <div class="label">Mode</div>
      <div class="value">${escapeHtml(auditMode.charAt(0).toUpperCase() + auditMode.slice(1))} Scan</div>
    </div>
    ${aiEnhanced ? '<div class="header-meta-item"><div class="label">Analysis</div><div class="value">AI-Enhanced</div></div>' : ''}
  </div>
</div>

<!-- spacer after header -->
<div style="height: 28px;"></div>

<!-- ═══ SCORE ════════════════════════════════════ -->
<div class="score-section">
  <div style="text-align:center;">
    <div class="score-ring">
      <svg viewBox="0 0 140 140">
        <circle cx="70" cy="70" r="54" fill="none" stroke="#e2e8f0" stroke-width="10"/>
        <circle cx="70" cy="70" r="54" fill="none"
          stroke="${scoreColor}"
          stroke-width="10"
          stroke-linecap="round"
          stroke-dasharray="${circumference}"
          stroke-dashoffset="${dashoffset}"
          transform="rotate(-90 70 70)"
          style="transition: stroke-dashoffset 1s ease;"/>
      </svg>
      <div class="score-number" style="color:${scoreColor};">${score}</div>
    </div>
    <div class="score-label">Compliance Score</div>
  </div>
  <div class="stats-grid">
    <div class="stat-box">
      <span class="stat-value ${trackingRequests > 0 ? 'color-bad' : 'color-good'}">${trackingRequests}</span>
      <span class="stat-label">Trackers</span>
    </div>
    <div class="stat-box">
      <span class="stat-value ${trackingCookies > 0 ? 'color-bad' : 'color-good'}">${trackingCookies}</span>
      <span class="stat-label">Cookies</span>
    </div>
    <div class="stat-box">
      <span class="stat-value ${bannerDetected ? 'color-good' : 'color-bad'}">${bannerDetected ? 'Yes' : 'No'}</span>
      <span class="stat-label">Banner</span>
    </div>
    ${acceptFlowWorks !== undefined && acceptFlowWorks !== null ? `
    <div class="stat-box">
      <span class="stat-value ${acceptFlowWorks ? 'color-good' : 'color-bad'}">${acceptFlowWorks ? 'Yes' : 'No'}</span>
      <span class="stat-label">Accept</span>
    </div>` : ''}
    ${rejectFlowClean !== undefined && rejectFlowClean !== null ? `
    <div class="stat-box">
      <span class="stat-value ${rejectFlowClean ? 'color-good' : 'color-bad'}">${rejectFlowClean ? 'Yes' : 'No'}</span>
      <span class="stat-label">Reject</span>
    </div>` : ''}
  </div>
</div>

<!-- ═══ VERIFICATION ═════════════════════════════ -->
<div class="verify-box">
  <strong>Verification Method</strong>
  <p>This audit was performed using Puppeteer with fresh browser contexts (equivalent to incognito mode) for each scenario, ensuring zero session contamination and reproducible results.</p>
</div>

<!-- ═══ AI SUMMARY ═══════════════════════════════ -->
${aiSummary.summary ? `
<div class="section">
  <div class="section-header">
    <span class="section-title">AI Compliance Analysis</span>
  </div>
  <div class="verdict-box verdict-${verdictClass}">
    ${escapeHtml(aiSummary.summary)}
  </div>
</div>` : ''}

<!-- ═══ CRITICAL ISSUES ══════════════════════════ -->
${issues.length > 0 ? `
<div class="issues-box">
  <h3>Critical Issues (${issues.length})</h3>
  ${issues.map(i => `<div class="issue-item">${escapeHtml(i)}</div>`).join('\n')}
</div>` : ''}

<!-- ═══ VIOLATIONS ═══════════════════════════════ -->
${violations.length > 0 ? `
<div class="section">
  <div class="section-header">
    <span class="section-title">Violations <span class="section-count">(${violations.length})</span></span>
  </div>
  <table class="data-table">
    <thead>
      <tr>
        <th style="width:70px;">Severity</th>
        <th style="width:140px;">Issue</th>
        <th>Details</th>
        <th style="width:100px;">Legal Ref</th>
        <th style="width:150px;">Recommendation</th>
      </tr>
    </thead>
    <tbody>
      ${violations.map(v => `
      <tr>
        <td>${severityTag(v.severity || 'medium')}</td>
        <td class="cell-bold">${escapeHtml(v.title || '')}</td>
        <td>${escapeHtml(v.description || '')}</td>
        <td><span class="legal-ref">${escapeHtml(v.legalRef || '')}</span></td>
        <td>${escapeHtml(v.recommendation || '')}</td>
      </tr>`).join('\n')}
    </tbody>
  </table>
</div>` : ''}

<!-- ═══ POSITIVES ════════════════════════════════ -->
${positives.length > 0 ? `
<div class="section">
  <div class="section-header">
    <span class="section-title">What's Done Right</span>
  </div>
  <div class="positives-box">
    ${positives.map(p => `<div class="positive-item">${escapeHtml(p)}</div>`).join('\n')}
  </div>
</div>` : ''}

<!-- ═══ RECOMMENDATIONS ══════════════════════════ -->
${recommendations.length > 0 ? `
<div class="section">
  <div class="section-header">
    <span class="section-title">Recommendations <span class="section-count">(by Priority)</span></span>
  </div>
  <div class="rec-box">
    ${recommendations.map((r, i) => `
    <div class="rec-item">
      <div class="rec-number">${i + 1}.</div>
      <div>${severityTag(r.priority === 'high' ? 'critical' : (r.priority || 'low'))}</div>
      <div class="rec-text">${escapeHtml(r.action || '')}</div>
    </div>`).join('\n')}
  </div>
</div>` : ''}

<div class="page-break"></div>

<!-- ═══ AUDIT CHECKS ═════════════════════════════ -->
${checks.length > 0 ? `
<div class="section">
  <div class="section-header">
    <span class="section-title">Audit Checks</span>
  </div>
  <table class="data-table">
    <thead>
      <tr>
        <th style="width:70px;">Result</th>
        <th style="width:200px;">Check</th>
        <th>Details</th>
      </tr>
    </thead>
    <tbody>
      ${checks.map(c => {
        const status = c.status || 'fail';
        const statusClass = status === 'pass' ? 'check-pass' : status === 'warning' ? 'check-warn' : 'check-fail';
        const statusLabel = status === 'pass' ? 'PASS' : status === 'warning' ? 'WARN' : 'FAIL';
        return `
      <tr>
        <td><span class="${statusClass}">${statusLabel}</span></td>
        <td class="cell-bold">${escapeHtml(c.name || '')}</td>
        <td>${escapeHtml(c.details || c.detail || '')}</td>
      </tr>`;
      }).join('\n')}
    </tbody>
  </table>
</div>` : ''}

<!-- ═══ TRACKING SERVICES ════════════════════════ -->
${Object.keys(trackingByService).length > 0 ? `
<div class="section">
  <div class="section-header">
    <span class="section-title">Tracking Services Detected <span class="section-count">(${Object.keys(trackingByService).length})</span></span>
  </div>
  <table class="data-table">
    <thead>
      <tr>
        <th>Service</th>
        <th style="width:80px;">Severity</th>
        <th style="width:70px;">Requests</th>
        <th>Example URL</th>
      </tr>
    </thead>
    <tbody>
      ${Object.entries(trackingByService).map(([service, info]) => `
      <tr>
        <td class="cell-bold">${escapeHtml(service)}</td>
        <td>${severityTag(info.severity || 'warning')}</td>
        <td style="text-align:center;font-weight:700;">${info.count || (Array.isArray(info) ? info.length : 0)}</td>
        <td style="font-size:9px;word-break:break-all;color:#94a3b8;">
          ${info.urls && info.urls[0] ? escapeHtml(truncate(info.urls[0], 90)) : ''}
        </td>
      </tr>`).join('\n')}
    </tbody>
  </table>
</div>` : ''}

<!-- ═══ COOKIES ══════════════════════════════════ -->
${cookies.length > 0 ? `
<div class="section">
  <div class="section-header">
    <span class="section-title">Cookies <span class="section-count">(${cookies.length})</span></span>
  </div>
  ${trackingCookieList.length > 0 ? `
  <div class="cookie-evidence">
    <strong style="color:#dc2626;">Tracking Cookies (${trackingCookieList.length}):</strong><br>
    ${trackingCookieList.map(c => `<span class="cookie-name">${escapeHtml(c.name || '')}</span> <span class="cookie-service">(${escapeHtml(c.classification?.service || '')} | ${escapeHtml(c.domain || '')})</span>`).join('<br>\n')}
  </div>` : ''}
  <table class="data-table">
    <thead>
      <tr>
        <th>Name</th>
        <th>Domain</th>
        <th style="width:60px;">Type</th>
        <th style="width:60px;">Secure</th>
        <th style="width:65px;">HttpOnly</th>
      </tr>
    </thead>
    <tbody>
      ${cookies.slice(0, 25).map(c => {
        const isTracking = (c.classification?.type) === 'tracking';
        return `
      <tr>
        <td style="font-weight:600;font-size:10px;">${escapeHtml(c.name || '')}</td>
        <td style="font-size:10px;color:#94a3b8;">${escapeHtml(c.domain || '')}</td>
        <td>${isTracking ? '<span class="check-fail">TRACK</span>' : '<span style="font-size:9px;color:#94a3b8;">Other</span>'}</td>
        <td style="text-align:center;">${c.secure ? '<span class="check-pass">YES</span>' : '<span class="check-fail">NO</span>'}</td>
        <td style="text-align:center;">${c.httpOnly ? '<span class="check-pass">YES</span>' : '<span class="check-fail">NO</span>'}</td>
      </tr>`;
      }).join('\n')}
    </tbody>
  </table>
  ${cookies.length > 25 ? `<div style="text-align:center;color:#94a3b8;margin-top:6px;font-size:10px;">… and ${cookies.length - 25} more cookies</div>` : ''}
</div>` : ''}

<!-- ═══ CONSENT FLOWS ════════════════════════════ -->
${(scenarios.acceptAll || scenarios.reject) ? `
<div class="section">
  <div class="section-header">
    <span class="section-title">Consent Flow Analysis</span>
  </div>
  ${scenarios.acceptAll ? `
  <div class="flow-section">
    <span class="flow-label">Accept-All Flow:</span>
    ${scenarios.acceptAll.clicked
      ? `Clicked "${escapeHtml(scenarios.acceptAll.clicked)}" — ${(scenarios.acceptAll.postTracking || []).length > 0
        ? `<span class="check-pass">${(scenarios.acceptAll.postTracking || []).length} tracking request(s) after accept — CMP working</span>`
        : '<span class="check-warn">No activity — CMP may not be working</span>'}`
      : '<span class="check-warn">Could not find Accept button</span>'}
  </div>` : ''}
  ${scenarios.reject ? `
  <div class="flow-section" style="margin-top:8px;">
    <span class="flow-label">Reject Flow:</span>
    ${scenarios.reject.clicked
      ? `Clicked "${escapeHtml(scenarios.reject.clicked)}" — ${(scenarios.reject.postTracking || []).length === 0
        ? '<span class="check-pass">Clean — no tracking after rejection</span>'
        : `<span class="check-fail">${(scenarios.reject.postTracking || []).length} tracking request(s) AFTER rejection!</span>
           <div class="cookie-evidence" style="margin-top:8px;">
             ${(scenarios.reject.postTracking || []).map(r =>
               `<span class="cookie-name">${escapeHtml((r.labels || []).join(', '))}</span> <span class="cookie-service">${escapeHtml(truncate(r.url || '', 80))}</span>`
             ).join('<br>')}
           </div>`}`
      : '<span class="check-warn">Could not find Reject button</span>'}
  </div>` : ''}
</div>` : ''}

<!-- ═══ FOOTER ═══════════════════════════════════ -->
<div class="footer">
  <strong>LSM — Landeseiten Maintenance</strong><br>
  Generated on ${escapeHtml(generatedAt)} | ${escapeHtml(auditMode.charAt(0).toUpperCase() + auditMode.slice(1))} Audit
  ${aiEnhanced ? ' | AI-Enhanced Analysis' : ''}<br>
  <div class="disclaimer">This audit is a point-in-time snapshot and does not constitute legal advice.</div>
</div>

</body>
</html>`;
};
