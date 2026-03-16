#!/usr/bin/env node
/**
 * Accessibility Audit Script — axe-core + Puppeteer
 *
 * Scans a website for WCAG 2.1 Level AA accessibility violations
 * using axe-core (the industry standard used by Lighthouse, Microsoft
 * Accessibility Insights, etc.)
 *
 * Usage:
 *   node accessibility-audit.js <url>
 *
 * Output: JSON to stdout
 */

const puppeteer = require('puppeteer-core');
const fs = require('fs');
const os = require('os');
const path = require('path');

const TARGET = process.argv[2];

if (!TARGET) {
    console.error(JSON.stringify({ success: false, error: 'URL argument is required' }));
    process.exit(1);
}

// ── Resolve axe-core source ──
const AXE_SOURCE = fs.readFileSync(
    require.resolve('axe-core/axe.min.js'),
    'utf8'
);

// ── Violation category mapping ──
const CATEGORY_MAP = {
    'color-contrast': 'Color & Contrast',
    'color-contrast-enhanced': 'Color & Contrast',
    'link-in-text-block': 'Color & Contrast',
    'image-alt': 'Images',
    'image-redundant-alt': 'Images',
    'input-image-alt': 'Images',
    'role-img-alt': 'Images',
    'svg-img-alt': 'Images',
    'area-alt': 'Images',
    'object-alt': 'Images',
    'label': 'Forms',
    'input-button-name': 'Forms',
    'select-name': 'Forms',
    'autocomplete-valid': 'Forms',
    'form-field-multiple-labels': 'Forms',
    'aria-input-field-name': 'ARIA',
    'aria-toggle-field-name': 'ARIA',
    'aria-required-attr': 'ARIA',
    'aria-required-children': 'ARIA',
    'aria-required-parent': 'ARIA',
    'aria-valid-attr': 'ARIA',
    'aria-valid-attr-value': 'ARIA',
    'aria-allowed-attr': 'ARIA',
    'aria-hidden-body': 'ARIA',
    'aria-hidden-focus': 'ARIA',
    'aria-roles': 'ARIA',
    'heading-order': 'Structure & Semantics',
    'empty-heading': 'Structure & Semantics',
    'page-has-heading-one': 'Structure & Semantics',
    'region': 'Structure & Semantics',
    'landmark-one-main': 'Structure & Semantics',
    'landmark-unique': 'Structure & Semantics',
    'bypass': 'Navigation',
    'tabindex': 'Navigation',
    'focus-order-semantics': 'Navigation',
    'link-name': 'Links & Buttons',
    'button-name': 'Links & Buttons',
    'duplicate-id': 'HTML Quality',
    'duplicate-id-active': 'HTML Quality',
    'duplicate-id-aria': 'HTML Quality',
    'html-has-lang': 'Language',
    'html-lang-valid': 'Language',
    'html-xml-lang-mismatch': 'Language',
    'valid-lang': 'Language',
    'document-title': 'Document',
    'meta-viewport': 'Document',
    'meta-refresh': 'Document',
    'td-headers-attr': 'Tables',
    'th-has-data-cells': 'Tables',
    'table-duplicate-name': 'Tables',
    'video-caption': 'Media',
    'audio-caption': 'Media',
    'no-autoplay-audio': 'Media',
};

function getCategory(ruleId) {
    return CATEGORY_MAP[ruleId] || 'Other';
}

// ── Impact severity scoring ──
const IMPACT_SCORES = {
    critical: 15,
    serious: 10,
    moderate: 5,
    minor: 2,
};

// ── WCAG criteria friendly names ──
const WCAG_NAMES = {
    '1.1.1': 'Non-text Content',
    '1.2.1': 'Audio-only and Video-only',
    '1.2.2': 'Captions (Prerecorded)',
    '1.2.3': 'Audio Description',
    '1.3.1': 'Info and Relationships',
    '1.3.2': 'Meaningful Sequence',
    '1.3.3': 'Sensory Characteristics',
    '1.3.4': 'Orientation',
    '1.3.5': 'Identify Input Purpose',
    '1.4.1': 'Use of Color',
    '1.4.2': 'Audio Control',
    '1.4.3': 'Contrast (Minimum)',
    '1.4.4': 'Resize Text',
    '1.4.5': 'Images of Text',
    '1.4.10': 'Reflow',
    '1.4.11': 'Non-text Contrast',
    '1.4.12': 'Text Spacing',
    '1.4.13': 'Content on Hover or Focus',
    '2.1.1': 'Keyboard',
    '2.1.2': 'No Keyboard Trap',
    '2.1.4': 'Character Key Shortcuts',
    '2.2.1': 'Timing Adjustable',
    '2.2.2': 'Pause, Stop, Hide',
    '2.3.1': 'Three Flashes',
    '2.4.1': 'Bypass Blocks',
    '2.4.2': 'Page Titled',
    '2.4.3': 'Focus Order',
    '2.4.4': 'Link Purpose (In Context)',
    '2.4.5': 'Multiple Ways',
    '2.4.6': 'Headings and Labels',
    '2.4.7': 'Focus Visible',
    '2.5.1': 'Pointer Gestures',
    '2.5.2': 'Pointer Cancellation',
    '2.5.3': 'Label in Name',
    '2.5.4': 'Motion Actuation',
    '3.1.1': 'Language of Page',
    '3.1.2': 'Language of Parts',
    '3.2.1': 'On Focus',
    '3.2.2': 'On Input',
    '3.2.3': 'Consistent Navigation',
    '3.2.4': 'Consistent Identification',
    '3.3.1': 'Error Identification',
    '3.3.2': 'Labels or Instructions',
    '3.3.3': 'Error Suggestion',
    '3.3.4': 'Error Prevention',
    '4.1.1': 'Parsing',
    '4.1.2': 'Name, Role, Value',
    '4.1.3': 'Status Messages',
};

function findChrome() {
    const paths = [
        '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
        '/Applications/Chromium.app/Contents/MacOS/Chromium',
        '/Applications/Brave Browser.app/Contents/MacOS/Brave Browser',
        '/usr/bin/google-chrome-stable', '/usr/bin/google-chrome',
        '/usr/bin/chromium', '/usr/bin/chromium-browser',
    ];
    for (const p of paths) { if (fs.existsSync(p)) return p; }
    return null;
}

// ── Custom checks (beyond axe-core) ──
async function customChecks(page) {
    return await page.evaluate(() => {
        const results = [];

        // 1. Viewport meta — blocks zoom?
        const viewport = document.querySelector('meta[name="viewport"]');
        if (viewport) {
            const content = (viewport.getAttribute('content') || '').toLowerCase();
            const blocksZoom = content.includes('maximum-scale=1') ||
                content.includes('user-scalable=no') ||
                content.includes('user-scalable=0');
            results.push({
                id: 'viewport-zoom',
                name: 'Viewport Zoom',
                category: 'Document',
                impact: blocksZoom ? 'serious' : null,
                status: blocksZoom ? 'fail' : 'pass',
                description: blocksZoom
                    ? 'Viewport meta tag prevents user zoom (maximum-scale=1 or user-scalable=no)'
                    : 'Viewport allows user zoom',
                wcag: '1.4.4',
                element: `<meta name="viewport" content="${viewport.getAttribute('content')}">`,
            });
        }

        // 2. Skip navigation link
        const skipLinks = document.querySelectorAll(
            'a[href^="#main"], a[href^="#content"], a[href^="#skip"], .skip-link, .skip-to-content, [class*="skip-nav"], [class*="skipnav"]'
        );
        // Also check first few links
        const firstLinks = Array.from(document.querySelectorAll('a')).slice(0, 5);
        const hasSkipByText = firstLinks.some(a => {
            const text = (a.textContent || '').toLowerCase();
            return text.includes('skip') || text.includes('zum inhalt') || text.includes('direkt zum');
        });
        const hasSkip = skipLinks.length > 0 || hasSkipByText;
        results.push({
            id: 'skip-navigation',
            name: 'Skip Navigation',
            category: 'Navigation',
            impact: hasSkip ? null : 'moderate',
            status: hasSkip ? 'pass' : 'fail',
            description: hasSkip
                ? 'Skip navigation link found'
                : 'No skip navigation link detected — keyboard users must tab through all navigation on every page',
            wcag: '2.4.1',
            element: null,
        });

        // 3. Touch target size (44x44 minimum, WCAG 2.5.5)
        const smallTargets = [];
        const interactiveEls = document.querySelectorAll('a, button, input, select, textarea, [role="button"], [role="link"], [tabindex]');
        for (const el of interactiveEls) {
            const rect = el.getBoundingClientRect();
            if (rect.width === 0 || rect.height === 0) continue; // hidden
            if (rect.width < 44 || rect.height < 44) {
                // Only flag if it's actually visible and not inline text
                const style = window.getComputedStyle(el);
                if (style.display === 'none' || style.visibility === 'hidden') continue;
                if (style.display === 'inline' && el.tagName === 'A') continue; // inline text links are fine
                smallTargets.push({
                    element: el.outerHTML.substring(0, 120),
                    width: Math.round(rect.width),
                    height: Math.round(rect.height),
                });
            }
        }
        if (smallTargets.length > 0) {
            results.push({
                id: 'touch-target-size',
                name: 'Touch Target Size',
                category: 'Interaction',
                impact: 'minor',
                status: smallTargets.length > 10 ? 'fail' : 'warning',
                description: `${smallTargets.length} interactive element(s) smaller than 44×44px minimum touch target`,
                wcag: '2.5.5',
                element: null,
                details: smallTargets.slice(0, 10), // Cap at 10 examples
            });
        } else {
            results.push({
                id: 'touch-target-size',
                name: 'Touch Target Size',
                category: 'Interaction',
                impact: null,
                status: 'pass',
                description: 'All interactive elements meet minimum 44×44px touch target size',
                wcag: '2.5.5',
                element: null,
            });
        }

        // 4. HTML lang attribute
        const html = document.documentElement;
        const lang = html.getAttribute('lang');
        results.push({
            id: 'html-lang',
            name: 'HTML Language',
            category: 'Language',
            impact: lang ? null : 'serious',
            status: lang ? 'pass' : 'fail',
            description: lang
                ? `Page language set to "${lang}"`
                : 'No lang attribute on <html> element — screen readers cannot determine page language',
            wcag: '3.1.1',
            element: `<html lang="${lang || ''}">`,
        });

        // 5. Page title
        const title = document.title;
        results.push({
            id: 'page-title',
            name: 'Page Title',
            category: 'Document',
            impact: title ? null : 'serious',
            status: title ? 'pass' : 'fail',
            description: title
                ? `Page title: "${title.substring(0, 80)}"`
                : 'No page title — users and screen readers cannot identify the page',
            wcag: '2.4.2',
            element: null,
        });

        // 6. Focus indicator check (simplified)
        const focusableEls = document.querySelectorAll('a, button, input, select, textarea, [tabindex]');
        let outlineRemoved = 0;
        for (const el of Array.from(focusableEls).slice(0, 30)) {
            const style = window.getComputedStyle(el);
            if (style.outlineStyle === 'none' && style.outlineWidth === '0px') {
                // Check if there's a substitute focus style
                const hasBorderChange = style.borderStyle !== 'none';
                if (!hasBorderChange) outlineRemoved++;
            }
        }
        if (outlineRemoved > 5) {
            results.push({
                id: 'focus-indicators',
                name: 'Focus Indicators',
                category: 'Navigation',
                impact: 'serious',
                status: 'warning',
                description: `${outlineRemoved} focusable element(s) have outline:none without visible focus alternative — keyboard users may lose track`,
                wcag: '2.4.7',
                element: null,
            });
        }

        return results;
    });
}

// ── Build structured report from axe results ──
function buildReport(axeResults, customResults, screenshotPath) {
    let score = 100;
    const issues = [];

    // Process axe-core violations
    const violations = (axeResults.violations || []).map(v => {
        const wcagTags = (v.tags || []).filter(t => t.startsWith('wcag') && !t.startsWith('wcag2'));
        const wcagCriteria = (v.tags || [])
            .filter(t => /^wcag\d{3,4}$/.test(t))
            .map(t => {
                // Convert wcag111 → 1.1.1, wcag1411 → 1.4.11
                const nums = t.replace('wcag', '');
                if (nums.length === 3) return `${nums[0]}.${nums[1]}.${nums[2]}`;
                if (nums.length === 4) return `${nums[0]}.${nums[1]}.${nums.slice(2)}`;
                return nums;
            });

        const nodeCount = (v.nodes || []).length;
        const deduction = (IMPACT_SCORES[v.impact] || 5) * Math.min(nodeCount, 3);
        score -= deduction;

        return {
            id: v.id,
            impact: v.impact,
            category: getCategory(v.id),
            description: v.description,
            help: v.help,
            helpUrl: v.helpUrl,
            wcag: wcagCriteria,
            wcagNames: wcagCriteria.map(c => WCAG_NAMES[c] || c),
            nodes: (v.nodes || []).slice(0, 5).map(n => ({
                html: (n.html || '').substring(0, 200),
                target: n.target,
                failureSummary: n.failureSummary,
            })),
            nodeCount,
        };
    });

    // Process custom check failures
    const customViolations = customResults.filter(c => c.status === 'fail' || c.status === 'warning');
    for (const cv of customViolations) {
        const deduction = IMPACT_SCORES[cv.impact] || 5;
        score -= deduction;
    }

    score = Math.max(score, 0);

    // Group violations by category
    const violationsByCategory = {};
    for (const v of violations) {
        if (!violationsByCategory[v.category]) violationsByCategory[v.category] = [];
        violationsByCategory[v.category].push(v);
    }

    // Count by impact
    const impactCounts = { critical: 0, serious: 0, moderate: 0, minor: 0 };
    for (const v of violations) {
        if (impactCounts[v.impact] !== undefined) impactCounts[v.impact]++;
    }
    for (const cv of customViolations) {
        if (cv.impact && impactCounts[cv.impact] !== undefined) impactCounts[cv.impact]++;
    }

    // Build issues list
    if (impactCounts.critical > 0) issues.push(`${impactCounts.critical} critical accessibility violation(s)`);
    if (impactCounts.serious > 0) issues.push(`${impactCounts.serious} serious accessibility violation(s)`);
    if (impactCounts.moderate > 0) issues.push(`${impactCounts.moderate} moderate accessibility violation(s)`);

    // Passes summary
    const passes = (axeResults.passes || []).map(p => ({
        id: p.id,
        description: p.description,
        help: p.help,
        category: getCategory(p.id),
        nodeCount: (p.nodes || []).length,
    }));

    // Incomplete checks (need manual review)
    const incomplete = (axeResults.incomplete || []).map(inc => ({
        id: inc.id,
        impact: inc.impact,
        description: inc.description,
        help: inc.help,
        category: getCategory(inc.id),
        nodeCount: (inc.nodes || []).length,
    }));

    return {
        url: TARGET,
        timestamp: new Date().toISOString(),
        score,
        issues,
        wcagLevel: 'AA',
        wcagVersion: '2.1',
        summary: {
            totalViolations: violations.length,
            totalNodes: violations.reduce((sum, v) => sum + v.nodeCount, 0),
            impactCounts,
            totalPasses: passes.length,
            totalIncomplete: incomplete.length,
            categories: Object.keys(violationsByCategory),
        },
        violations,
        violationsByCategory,
        customChecks: customResults,
        passes,
        incomplete,
        screenshotPath,
    };
}

// ── MAIN ──
async function main() {
    const chrome = findChrome();
    if (!chrome) {
        console.error(JSON.stringify({ success: false, error: 'Chrome/Chromium not found. Install Google Chrome.' }));
        process.exit(1);
    }

    const browser = await puppeteer.launch({
        headless: 'new',
        executablePath: chrome,
        args: [
            '--no-sandbox',
            '--disable-setuid-sandbox',
            '--disable-dev-shm-usage',
            '--window-size=1280,800',
        ],
    });

    let screenshotPath = null;

    try {
        const page = await browser.newPage();
        await page.setViewport({ width: 1280, height: 800 });
        await page.setUserAgent('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36');

        // Navigate to target
        try {
            await page.goto(TARGET, { waitUntil: 'networkidle2', timeout: 30000 });
        } catch (e) {
            // Timeout is ok, page may still be usable
        }

        // Wait for page to settle
        await new Promise(r => setTimeout(r, 2000));

        // ── Dismiss cookie consent banners ──
        // Try clicking common consent/accept buttons to reveal the actual page
        try {
            const consentDismissed = await page.evaluate(() => {
                // Common consent button selectors (German + English)
                const selectors = [
                    // Generic consent buttons
                    '[class*="consent"] button', '[class*="cookie"] button',
                    '[id*="consent"] button', '[id*="cookie"] button',
                    '[class*="banner"] button[class*="accept"]',
                    // Popular consent solutions
                    '.cc-btn.cc-allow', '.cc-accept', '.cc-dismiss',
                    '#CybotCookiebotDialogBodyLevelButtonLevelOptinAllowAll',
                    '#CybotCookiebotDialogBodyButtonAccept',
                    '.cmplz-accept', '#cmplz-cookiebanner-container .cmplz-btn',
                    '.borlabs-cookie-accept', '#BorlabsCookieBox .cookie-accept',
                    '.real-cookie-banner-accept', '[data-testid="uc-accept-all-button"]',
                    '.sp_choice_type_11', // SourcePoint
                    '#onetrust-accept-btn-handler',
                    '.fc-button.fc-cta-consent', // Funding Choices
                    // General patterns
                    'button[data-action="accept"]',
                    'button[data-cookieconsent="accept"]',
                    'a.cookie-consent-accept',
                ];
                for (const sel of selectors) {
                    const btn = document.querySelector(sel);
                    if (btn && btn.offsetParent !== null) {
                        btn.click();
                        return `Clicked: ${sel}`;
                    }
                }
                // Fallback: find buttons with accept/akzeptieren text
                const allButtons = document.querySelectorAll('button, a.btn, a.button, [role="button"]');
                const acceptTexts = ['accept all', 'accept cookies', 'alle akzeptieren', 'akzeptieren',
                    'alle annehmen', 'annehmen', 'zustimmen', 'agree', 'allow all',
                    'i agree', 'got it', 'verstanden', 'ok', 'einverstanden'];
                for (const btn of allButtons) {
                    const text = (btn.textContent || '').trim().toLowerCase();
                    if (text.length > 50) continue; // Too long to be a button label
                    for (const accept of acceptTexts) {
                        if (text === accept || text.startsWith(accept)) {
                            btn.click();
                            return `Clicked by text: "${text}"`;
                        }
                    }
                }
                return null;
            });
            if (consentDismissed) {
                // Wait for consent banner to disappear
                await new Promise(r => setTimeout(r, 1500));
            }
        } catch (e) {
            // Non-fatal
        }

        // ── Remove fixed/sticky overlays that block content ──
        try {
            await page.evaluate(() => {
                // Remove common overlay/modal containers
                const overlaySelectors = [
                    '[class*="cookie-banner"]', '[class*="cookiebanner"]',
                    '[class*="cookie-consent"]', '[class*="cookie-notice"]',
                    '[id*="cookie-banner"]', '[id*="cookiebanner"]',
                    '[class*="consent-banner"]', '[class*="consent-modal"]',
                    '.cc-window', '.cc-banner',
                    '#CybotCookiebotDialog', '#CybotCookiebotDialogBodyUnderlay',
                    '.borlabs-cookie', '#BorlabsCookieBox',
                    '[class*="gdpr"]', '[class*="privacy-banner"]',
                    '.cmplz-cookiebanner', '#cmplz-cookiebanner-container',
                    '#onetrust-banner-sdk', '#onetrust-consent-sdk',
                ];
                for (const sel of overlaySelectors) {
                    document.querySelectorAll(sel).forEach(el => {
                        el.style.display = 'none';
                        el.setAttribute('aria-hidden', 'true');
                    });
                }
                // Also remove any fixed/sticky full-width overlays
                const allEls = document.querySelectorAll('div, aside, section');
                for (const el of allEls) {
                    const style = window.getComputedStyle(el);
                    if ((style.position === 'fixed' || style.position === 'sticky') && style.zIndex > 999) {
                        const rect = el.getBoundingClientRect();
                        // If it covers most of the viewport, it's likely an overlay
                        if (rect.width > window.innerWidth * 0.5 && rect.height > window.innerHeight * 0.3) {
                            el.style.display = 'none';
                            el.setAttribute('aria-hidden', 'true');
                        }
                    }
                }
                // Make sure body is scrollable
                document.body.style.overflow = 'auto';
                document.documentElement.style.overflow = 'auto';
            });
        } catch (e) {
            // Non-fatal
        }

        // ── Scroll page to trigger lazy-loaded content ──
        try {
            await page.evaluate(async () => {
                const scrollStep = window.innerHeight;
                const maxScroll = Math.min(document.body.scrollHeight, window.innerHeight * 5);
                for (let y = 0; y < maxScroll; y += scrollStep) {
                    window.scrollTo(0, y);
                    await new Promise(r => setTimeout(r, 300));
                }
                // Scroll back to top
                window.scrollTo(0, 0);
            });
            await new Promise(r => setTimeout(r, 1000));
        } catch (e) {
            // Non-fatal  
        }

        // Take screenshot for AI analysis
        try {
            screenshotPath = path.join(os.tmpdir(), `a11y-screenshot-${Date.now()}.png`);
            await page.screenshot({ path: screenshotPath, fullPage: false });
        } catch (e) {
            screenshotPath = null;
        }

        // Inject and run axe-core
        await page.evaluate(AXE_SOURCE);

        const axeResults = await page.evaluate(() => {
            return new Promise((resolve, reject) => {
                // eslint-disable-next-line no-undef
                axe.run(document, {
                    runOnly: {
                        type: 'tag',
                        values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'best-practice'],
                    },
                    resultTypes: ['violations', 'passes', 'incomplete'],
                }).then(resolve).catch(reject);
            });
        });

        // Run custom checks
        const customResults = await customChecks(page);

        // Build final report
        const report = buildReport(axeResults, customResults, screenshotPath);

        console.log(JSON.stringify({ success: true, data: report }));

    } catch (e) {
        console.error(JSON.stringify({ success: false, error: e.message }));
        await browser.close();
        process.exit(1);
    }

    await browser.close();
}

main().catch(e => {
    console.error(JSON.stringify({ success: false, error: e.message }));
    process.exit(1);
});
