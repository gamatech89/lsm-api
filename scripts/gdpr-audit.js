#!/usr/bin/env node
/**
 * GDPR Audit Script — Dual Mode (Quick + Full)
 *
 * Based on the proven gdpr-compliance-checker audit-puppeteer.js.
 * Uses fresh browser contexts (incognito) for each scenario.
 *
 * Usage:
 *   node gdpr-audit.js <url> [--mode=quick|full]
 *
 * Quick mode: Pre-consent check only (~15s)
 * Full mode:  4 scenarios — pre-consent, banner UI, accept-all, reject (~45s)
 *
 * Output: JSON to stdout
 */

const puppeteer = require('puppeteer-core');
const fs = require('fs');
const os = require('os');
const path = require('path');

const TARGET = process.argv[2];
const MODE = (process.argv.find(a => a.startsWith('--mode=')) || '--mode=quick').split('=')[1];

if (!TARGET) {
    console.error(JSON.stringify({ success: false, error: 'URL argument is required' }));
    process.exit(1);
}

const WAIT = MODE === 'full' ? 10000 : 3000; // Full waits longer for delayed scripts

// ── Tracking domain patterns ──
const TRACKING = [
    [/facebook\.com\/tr/i, 'Meta Pixel', 'critical'],
    [/connect\.facebook\.net/i, 'Facebook SDK', 'critical'],
    [/fbevents/i, 'FB Events', 'critical'],
    [/googletagmanager\.com/i, 'GTM', 'critical'],
    [/google-analytics\.com/i, 'Google Analytics', 'critical'],
    [/analytics\.google\.com/i, 'GA4', 'critical'],
    [/doubleclick\.net/i, 'DoubleClick', 'critical'],
    [/googlesyndication/i, 'Google Ads', 'critical'],
    [/googleadservices/i, 'Google Ads Conversion', 'critical'],
    [/fonts\.googleapis\.com/i, 'Google Fonts API', 'warning'],
    [/fonts\.gstatic\.com/i, 'Google Fonts Static', 'warning'],
    [/player\.vimeo\.com/i, 'Vimeo Player', 'warning'],
    [/youtube\.com\/embed/i, 'YouTube Embed', 'warning'],
    [/youtube\.com\/iframe_api/i, 'YouTube API', 'warning'],
    [/maps\.googleapis\.com/i, 'Google Maps', 'warning'],
    [/maps\.google\.com/i, 'Google Maps', 'warning'],
    [/hotjar\.com/i, 'Hotjar', 'critical'],
    [/clarity\.ms/i, 'MS Clarity', 'critical'],
    [/linkedin\.com.*tracking/i, 'LinkedIn Tracking', 'critical'],
    [/snap\.licdn\.com/i, 'LinkedIn Insight', 'critical'],
    [/tiktok\.com/i, 'TikTok Pixel', 'critical'],
    [/mc\.yandex\.ru/i, 'Yandex Metrica', 'critical'],
    [/metrika\.yandex\.ru/i, 'Yandex Metrica', 'critical'],
    [/recaptcha/i, 'reCAPTCHA', 'warning'],
];

// Known tracking cookie patterns
const TRACKING_COOKIES = [
    [/^_ga$/, 'Google Analytics'],
    [/^_ga_/, 'GA4'],
    [/^_gid$/, 'Google Analytics'],
    [/^_gat/, 'Google Analytics'],
    [/^_gcl_/, 'Google Ads'],
    [/^_fbp$/, 'Facebook Pixel'],
    [/^_fbc$/, 'Facebook Pixel'],
    [/^_ym_uid$/, 'Yandex Metrica'],
    [/^_ym_d$/, 'Yandex Metrica'],
    [/^_ym_isad$/, 'Yandex Metrica'],
    [/^_ym_visorc$/, 'Yandex Metrica'],
    [/^_hj/, 'Hotjar'],
    [/^_clck$/, 'MS Clarity'],
    [/^_clsk$/, 'MS Clarity'],
    [/^IDE$/, 'DoubleClick'],
    [/^fr$/, 'Facebook'],
    [/^tr$/, 'Facebook'],
    [/^NID$/, 'Google'],
    [/^__gads$/, 'Google Ads'],
];

// Accept/Reject button patterns (DE + EN)
const ACCEPT_PATTERNS = [
    'ich akzeptiere alle', 'alle akzeptieren', 'accept all',
    'i accept everyone', 'all accept', 'i accept all',
    'alle cookies akzeptieren', 'zustimmen', 'accept',
];

const REJECT_PATTERNS = [
    'nur essenzielle cookies akzeptieren', 'nur essenzielle',
    'accept only essential cookies', 'accept only essential',
    'nur notwendige', 'nur notwendige cookies', 'ablehnen',
    'reject all', 'refuse all', 'deny all', 'decline',
];

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

// Anti-detection: make Puppeteer look like a real browser
async function setupStealthPage(page) {
    await page.evaluateOnNewDocument(() => {
        // Override navigator.webdriver
        Object.defineProperty(navigator, 'webdriver', { get: () => false });
        // Override chrome.runtime to look real
        window.chrome = { runtime: {}, loadTimes: () => {}, csi: () => {} };
        // Override permissions
        const origQuery = window.navigator.permissions?.query;
        if (origQuery) {
            window.navigator.permissions.query = (params) => (
                params.name === 'notifications'
                    ? Promise.resolve({ state: Notification.permission })
                    : origQuery(params)
            );
        }
        // Override plugins to look real
        Object.defineProperty(navigator, 'plugins', {
            get: () => [1, 2, 3, 4, 5],
        });
        // Override languages
        Object.defineProperty(navigator, 'languages', {
            get: () => ['de-DE', 'de', 'en-US', 'en'],
        });
    });
    await page.setUserAgent('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36');
}

function labelRequest(url) {
    const out = [];
    for (const [re, name, severity] of TRACKING) {
        if (re.test(url)) out.push({ name, severity });
    }
    return out;
}

function classifyCookie(name) {
    for (const [pattern, service] of TRACKING_COOKIES) {
        if (pattern.test(name)) return { type: 'tracking', service };
    }
    return { type: 'other', service: null };
}

async function clickButton(page, patterns) {
    let clicked = await tryClick(page, patterns);
    if (clicked) return clicked;

    // Fallback: If banner is hidden, try clicking the privacy preference widget in the corner
    const widgetClicked = await page.evaluate(() => {
        const w = document.querySelector('.borlabs-cookie-preference, .borlabs-cookie-preference-button, [class*="brlbs-cmp-preferences-btn"], [id*="borlabs-cookie-preference"], a[href="#borlabs-cookie-preference"]');
        if (w) { w.click(); return true; }
        // Fallback: bottom-left fixed elements that look like a cookie widget
        for (const el of document.querySelectorAll('div, a, button')) {
            const style = window.getComputedStyle(el);
            if (style.position === 'fixed' && parseInt(style.bottom) < 100 && parseInt(style.left) < 100) {
                if (el.innerHTML.includes('svg') || el.innerHTML.includes('img')) {
                    el.click(); return true;
                }
            }
        }
        return false;
    });

    if (widgetClicked) {
        await new Promise(r => setTimeout(r, 1500));
        clicked = await tryClick(page, patterns);
    }
    
    return clicked;
}

// Helper for clickButton
async function tryClick(page, patterns) {
    // Try regular DOM
    let clicked = await page.evaluate((pats) => {
        const btns = Array.from(document.querySelectorAll('a, button, div[role="button"]'));
        for (const pattern of pats) {
            const btn = btns.find(b => {
                const t = b.textContent.trim().toLowerCase();
                return t === pattern || t.includes(pattern);
            });
            if (btn && btn.offsetHeight > 0) { btn.click(); return btn.textContent.trim(); }
        }
        return null;
    }, patterns);
    if (clicked) return clicked;

    // Try Shadow DOM
    clicked = await page.evaluate((pats) => {
        const allDivs = document.querySelectorAll('div');
        for (const div of allDivs) {
            if (!div.shadowRoot) continue;
            const btns = Array.from(div.shadowRoot.querySelectorAll('a, button, div[role="button"]'));
            for (const pattern of pats) {
                const btn = btns.find(b => {
                    const t = b.textContent.trim().toLowerCase();
                    return t === pattern || t.includes(pattern);
                });
                if (btn && btn.offsetHeight > 0) { btn.click(); return btn.textContent.trim(); }
            }
        }
        return null;
    }, patterns);
    return clicked;
}

// ── Scenario runner (from the proven audit-puppeteer.js) ──
async function scenario(browser, name, clickPatterns) {
    const ctx = await browser.createBrowserContext();
    const page = await ctx.newPage();
    await setupStealthPage(page);

    const pre = [], post = [];
    let phase = 'pre';

    page.on('request', r => {
        const u = r.url(), labels = labelRequest(u);
        if (labels.length > 0) {
            (phase === 'pre' ? pre : post).push({
                url: u,
                labels: labels.map(l => l.name),
                severity: labels[0].severity,
                type: r.resourceType(),
            });
        }
    });

    const logs = [];
    page.on('console', m => {
        const t = m.text();
        if (/pixel|fbevents|gtm|gtag|analytics|consent|tracking|cookie|metrica/i.test(t)) {
            logs.push({ type: m.type(), text: t.substring(0, 300), phase });
        }
    });

    try { await page.goto(TARGET, { waitUntil: 'load', timeout: 30000 }); }
    catch (e) { /* timeout is ok, we still capture requests */ }

    await new Promise(r => setTimeout(r, WAIT));
    const cookies1 = await page.cookies();

    // Interaction (accept or reject)
    let interactResult = null;
    if (clickPatterns && clickPatterns.length > 0) {
        phase = 'post';
        const clicked = await clickButton(page, clickPatterns);
        interactResult = { clicked };

        if (clicked) {
            await new Promise(r => setTimeout(r, WAIT));
            const cookies2 = await page.cookies();
            const newCookies = cookies2.filter(c2 => !cookies1.some(c1 => c1.name === c2.name));

            interactResult.postTracking = post.map(r => ({ url: r.url, labels: r.labels, severity: r.severity }));
            interactResult.newCookies = newCookies.map(c => ({
                name: c.name,
                domain: c.domain,
                value: c.value.substring(0, 50),
                classification: classifyCookie(c.name),
            }));
            interactResult.allPostCookies = cookies2.map(c => ({
                name: c.name,
                domain: c.domain,
                value: c.value.substring(0, 50),
                classification: classifyCookie(c.name),
            }));
        }
    }

    // Classify pre-interaction cookies
    const classifiedCookies = cookies1.map(c => ({
        name: c.name,
        value: c.value.substring(0, 50),
        domain: c.domain,
        secure: c.secure,
        httpOnly: c.httpOnly,
        expires: c.expires,
        classification: classifyCookie(c.name),
    }));

    const trackingCookies = classifiedCookies.filter(c => c.classification.type === 'tracking');

    const result = {
        scenario: name,
        preTracking: pre.map(r => ({ url: r.url, labels: r.labels, severity: r.severity })),
        cookies: classifiedCookies,
        trackingCookies: trackingCookies.map(c => ({ name: c.name, service: c.classification.service, domain: c.domain, value: c.value })),
        consoleLogs: logs,
        interact: interactResult,
    };

    await ctx.close();
    return result;
}

// ── Banner UI analyzer ──
async function bannerCheck(browser) {
    const ctx = await browser.createBrowserContext();
    const page = await ctx.newPage();
    await setupStealthPage(page);

    try { await page.goto(TARGET, { waitUntil: 'load', timeout: 30000 }); }
    catch (e) { /* ok */ }
    await new Promise(r => setTimeout(r, 4000));

    // Try opening widget to reveal hidden banner
    const widgetClicked = await page.evaluate(() => {
        const w = document.querySelector('.borlabs-cookie-preference, .borlabs-cookie-preference-button, [class*="brlbs-cmp-preferences-btn"], [id*="borlabs-cookie-preference"], a[href="#borlabs-cookie-preference"]');
        if (w) { w.click(); return true; }
        for (const el of document.querySelectorAll('div, a, button')) {
            const style = window.getComputedStyle(el);
            if (style.position === 'fixed' && parseInt(style.bottom) < 100 && parseInt(style.left) < 100) {
                if (el.innerHTML.includes('svg') || el.innerHTML.includes('img')) { el.click(); return true; }
            }
        }
        return false;
    });

    if (widgetClicked) {
        await new Promise(r => setTimeout(r, 1500));
    }

    const info = await page.evaluate(() => {
        const result = { bannerFound: false, bannerText: '', buttons: [], checkboxes: [], links: [], solution: null };

        // Detect CMP banners (including Shadow DOM for Borlabs v3)
        const selectors = [
            '[class*="_brlbs"]', '[id*="BorlabsCookie"]', '[class*="borlabs"]',
            '[id*="CookieYes"]', '[class*="cky-"]',
            '[id*="CookieBox"]', '[class*="cookie-notice"]',
            '[class*="cc-banner"]', '[id*="cookiebanner"]',
            '[class*="rcb-"]', '[id*="real-cookie-banner"]',
            '#CybotCookiebotDialog', '[class*="cookiebot"]',
            '#cmplz-cookiebanner', '[class*="cmplz"]',
            '#onetrust-banner-sdk',
            '#usercentrics-root', '[class*="uc-"]',
            '#didomi-host', '[class*="didomi"]',
            '#truste-consent-track', '[class*="truste"]',
            '#iubenda-cs-banner', '[class*="iubenda"]',
            '.qc-cmp2-container', '#qc-cmp2-container',
            '[class*="klaro"]', '#klaro',
            '[class*="sp_choice"]', '#sp_message_container',
        ];

        const banner = document.querySelector(selectors.join(', '));
        if (banner) {
            result.bannerFound = true;
            result.bannerText = banner.textContent.substring(0, 600).replace(/\s+/g, ' ').trim();
        }

        // Shadow DOM check (Borlabs v3)
        if (!result.bannerFound) {
            const allDivs = document.querySelectorAll('div');
            for (const div of allDivs) {
                if (div.shadowRoot) {
                    const shadowHtml = div.shadowRoot.innerHTML || '';
                    if (shadowHtml.includes('borlabs') || shadowHtml.includes('cookie-box') || shadowHtml.includes('consent')) {
                        result.bannerFound = true;
                        result.solution = 'borlabs-v3-shadow';
                        result.bannerText = div.shadowRoot.textContent?.substring(0, 600).replace(/\s+/g, ' ').trim() || '';
                        // Get buttons from shadow DOM
                        const shadowBtns = div.shadowRoot.querySelectorAll('a, button');
                        shadowBtns.forEach(b => {
                            const txt = b.textContent.trim();
                            if (txt.length < 3 || txt.length > 80) return;
                            const s = window.getComputedStyle(b);
                            result.buttons.push({ text: txt, bg: s.backgroundColor, color: s.color, fontSize: s.fontSize });
                        });
                        break;
                    }
                }
            }
        }

        // Fallback text search
        if (!result.bannerFound) {
            document.querySelectorAll('div, section, aside, dialog').forEach(el => {
                const t = el.textContent;
                if ((t.includes('Privacy') || t.includes('cookie') || t.includes('Datenschutz') || t.includes('Einwilligung')) && el.offsetHeight > 200) {
                    result.bannerFound = true;
                    result.bannerText = t.substring(0, 600).replace(/\s+/g, ' ').trim();
                }
            });
        }

        // Detect solution name
        if (!result.solution) {
            if (document.querySelector('[class*="borlabs"], [id*="BorlabsCookie"]')) result.solution = 'Borlabs Cookie';
            else if (document.querySelector('[id*="CookieYes"], [class*="cky-"]')) result.solution = 'CookieYes';
            else if (document.querySelector('[class*="rcb-"]')) result.solution = 'Real Cookie Banner';
            else if (document.querySelector('#CybotCookiebotDialog')) result.solution = 'Cookiebot';
            else if (document.querySelector('#cmplz-cookiebanner')) result.solution = 'Complianz';
            else if (document.querySelector('#onetrust-banner-sdk')) result.solution = 'OneTrust';
            else if (document.querySelector('#usercentrics-root')) result.solution = 'Usercentrics';
            else if (document.querySelector('#didomi-host')) result.solution = 'Didomi';
            else if (document.querySelector('#truste-consent-track')) result.solution = 'TrustArc';
            else if (document.querySelector('#iubenda-cs-banner')) result.solution = 'Iubenda';
            else if (document.querySelector('.qc-cmp2-container, #qc-cmp2-container')) result.solution = 'Quantcast Choice';
            else if (document.querySelector('[class*="klaro"], #klaro')) result.solution = 'Klaro';
            else if (result.bannerFound) result.solution = 'Unknown CMP';
        }

        // Consent buttons (regular DOM)
        if (result.buttons.length === 0) {
            document.querySelectorAll('a, button').forEach(b => {
                const txt = b.textContent.trim();
                if (txt.length < 3 || txt.length > 80) return;
                const s = window.getComputedStyle(b);
                if (s.display === 'none' || s.visibility === 'hidden' || b.offsetHeight === 0) return;
                const low = txt.toLowerCase();
                if (low.includes('accept') || low.includes('essential') || low.includes('save') ||
                    low.includes('consent') || low.includes('individual') || low.includes('preference') ||
                    low.includes('akzeptier') || low.includes('notwendig') || low.includes('speichern') ||
                    low.includes('einwilligung') || low.includes('datenschutz') || low.includes('ablehnen') ||
                    low.includes('refuse') || low.includes('reject') || low.includes('deny')) {
                    result.buttons.push({ text: txt, bg: s.backgroundColor, color: s.color, fontSize: s.fontSize });
                }
            });
        }

        // Checkboxes
        document.querySelectorAll('input[type="checkbox"]').forEach(cb => {
            let lbl = '';
            const p = cb.closest('div, label, li, fieldset');
            if (p) {
                const l = p.querySelector('span, label, strong, h3, h4, [class*="title"], [class*="label"], [class*="name"]');
                lbl = l ? l.textContent.trim().substring(0, 60) : p.textContent.trim().substring(0, 60);
            }
            const forL = document.querySelector(`label[for="${cb.id}"]`);
            if (forL) lbl = forL.textContent.trim().substring(0, 60);
            const isEssential = (lbl || '').toLowerCase().match(/essential|essenziell|notwendig|necessary/);
            result.checkboxes.push({
                checked: cb.checked, disabled: cb.disabled,
                label: lbl || cb.id, isEssential: !!isEssential,
                preTicked: cb.checked && !cb.disabled && !isEssential,
            });
        });

        // Legal links
        document.querySelectorAll('a').forEach(a => {
            const t = (a.textContent || '').toLowerCase(), h = (a.href || '').toLowerCase();
            if (t.includes('privacy') || t.includes('datenschutz') || t.includes('imprint') ||
                t.includes('impressum') || t.includes('policy') ||
                h.includes('privacy') || h.includes('datenschutz') || h.includes('imprint') || h.includes('impressum')) {
                result.links.push({ text: a.textContent.trim().substring(0, 50), href: a.href });
            }
        });

        return result;
    });

    await ctx.close();
    return info;
}

// ── Build checks and score ──
function buildReport(results, mode) {
    const checks = [];
    let score = 100;
    const issues = [];

    const pre = results.preConsent;

    // ── Check 1: Pre-consent tracking requests
    const preTrackingCount = pre?.preTracking?.length || 0;
    const preServices = [...new Set((pre?.preTracking || []).flatMap(r => r.labels))];
    const criticalPreServices = preServices.filter(s => {
        const match = TRACKING.find(([, name]) => name === s);
        return match && match[2] === 'critical';
    });

    checks.push({
        name: 'Tracking Requests (pre-consent)',
        status: preTrackingCount === 0 ? 'pass' : 'fail',
        severity: criticalPreServices.length > 0 ? 'critical' : (preTrackingCount > 0 ? 'warning' : 'ok'),
        details: preTrackingCount === 0
            ? 'No tracking requests detected before consent'
            : `${preTrackingCount} tracking request(s) fired before any consent interaction`,
        services: preServices.reduce((acc, s) => {
            const count = (pre?.preTracking || []).filter(r => r.labels.includes(s)).length;
            acc[s] = { count, severity: TRACKING.find(([, name]) => name === s)?.[2] || 'warning' };
            return acc;
        }, {}),
    });
    if (criticalPreServices.length > 0) {
        score -= Math.min(criticalPreServices.length * 15, 45);
        issues.push(`${criticalPreServices.length} tracking service(s) loaded before consent: ${criticalPreServices.join(', ')}`);
    }

    // ── Check 2: Pre-consent tracking cookies
    const preTrackingCookies = pre?.trackingCookies || [];
    checks.push({
        name: 'Tracking Cookies (pre-consent)',
        status: preTrackingCookies.length === 0 ? 'pass' : 'fail',
        severity: preTrackingCookies.length > 0 ? 'critical' : 'ok',
        details: preTrackingCookies.length === 0
            ? 'No tracking cookies set before consent'
            : `${preTrackingCookies.length} tracking cookie(s) set before consent: ${preTrackingCookies.map(c => c.name).join(', ')}`,
        cookies: preTrackingCookies.reduce((acc, c) => {
            if (!acc[c.service]) acc[c.service] = [];
            acc[c.service].push(c.name);
            return acc;
        }, {}),
    });
    if (preTrackingCookies.length > 0) {
        const uniqueServices = [...new Set(preTrackingCookies.map(c => c.service))];
        score -= Math.min(uniqueServices.length * 10, 30);
        issues.push(`${preTrackingCookies.length} tracking cookie(s) before consent: ${preTrackingCookies.map(c => c.name).join(', ')}`);
    }

    // ── Check 3: Google Fonts
    const gFonts = (pre?.preTracking || []).filter(r => r.labels.includes('Google Fonts API') || r.labels.includes('Google Fonts Static'));
    checks.push({
        name: 'Google Fonts',
        status: gFonts.length > 0 ? 'warning' : 'pass',
        severity: gFonts.length > 0 ? 'warning' : 'ok',
        details: gFonts.length > 0
            ? `Google Fonts loaded externally (${gFonts.length} request(s)) — user IPs transmitted to Google without consent (LG München I ruling)`
            : 'No external Google Fonts requests — fonts appear locally hosted',
    });
    if (gFonts.length > 0) {
        score -= 10;
        issues.push('Google Fonts loaded externally — IP transmitted to Google without consent');
    }

    // ── Check 4: Cookie Banner (full mode only)
    if (results.banner) {
        const hasDarkPatterns = (results.banner.checkboxes || []).some(c => c.preTicked);
        checks.push({
            name: 'Cookie Consent Banner',
            status: results.banner.bannerFound ? 'pass' : 'fail',
            severity: results.banner.bannerFound ? 'ok' : 'critical',
            details: results.banner.bannerFound
                ? `Cookie consent banner detected: ${results.banner.solution || 'Unknown'}`
                : 'No cookie consent banner detected — required under GDPR/TDDDG § 25',
        });
        if (!results.banner.bannerFound) {
            score -= 20;
            issues.push('No cookie consent banner detected');
        }

        // Dark patterns
        checks.push({
            name: 'Dark Patterns',
            status: hasDarkPatterns ? 'warning' : 'pass',
            severity: hasDarkPatterns ? 'warning' : 'ok',
            details: hasDarkPatterns
                ? `Pre-ticked non-essential checkbox(es) detected: ${(results.banner.checkboxes || []).filter(c => c.preTicked).map(c => c.label).join(', ')}`
                : 'No pre-ticked non-essential checkboxes — compliant',
        });
        if (hasDarkPatterns) {
            score -= 10;
            issues.push('Pre-ticked non-essential checkboxes detected (dark pattern)');
        }
    }

    // ── Check 5: Accept-All flow (full mode only)
    if (results.acceptAll) {
        const postTracking = results.acceptAll.interact?.postTracking || [];
        checks.push({
            name: 'Tracking After Accept',
            status: postTracking.length > 0 ? 'pass' : 'warning',
            severity: postTracking.length > 0 ? 'ok' : 'warning',
            details: postTracking.length > 0
                ? `${postTracking.length} tracking request(s) fired after accepting — CMP is working`
                : 'No tracking activated after accepting — CMP may be misconfigured',
            services: postTracking.reduce((acc, r) => {
                for (const l of r.labels) {
                    if (!acc[l]) acc[l] = { count: 0 };
                    acc[l].count++;
                }
                return acc;
            }, {}),
        });
        if (postTracking.length === 0) {
            score -= 5;
            issues.push('No tracking activates after "Accept All" — CMP may be misconfigured');
        }
    }

    // ── Check 6: Reject flow (full mode — MOST CRITICAL)
    if (results.reject) {
        const rejectPostTracking = results.reject.interact?.postTracking || [];
        const rejectPreTracking = results.reject.preTracking || [];
        checks.push({
            name: 'No Tracking After Reject',
            status: rejectPostTracking.length === 0 ? 'pass' : 'fail',
            severity: rejectPostTracking.length > 0 ? 'critical' : 'ok',
            details: rejectPostTracking.length === 0
                ? 'No tracking after rejection — reject flow works correctly'
                : `❌ ${rejectPostTracking.length} tracking request(s) AFTER rejection — critical GDPR violation!`,
            services: rejectPostTracking.reduce((acc, r) => {
                for (const l of r.labels) {
                    if (!acc[l]) acc[l] = { count: 0, severity: r.severity };
                    acc[l].count++;
                }
                return acc;
            }, {}),
        });
        if (rejectPostTracking.length > 0) {
            score -= 25;
            issues.push(`Tracking fires after rejection — ${rejectPostTracking.length} request(s): ${[...new Set(rejectPostTracking.flatMap(r => r.labels))].join(', ')}`);
        }

        // Also check: did the pre-consent tracking also fire in this scenario?
        if (rejectPreTracking.length > 0) {
            // Already counted in pre-consent check, just note it
        }
    }

    score = Math.max(score, 0);

    // Build tracking services summary
    const trackingByService = {};
    for (const req of (pre?.preTracking || [])) {
        for (const label of req.labels) {
            if (!trackingByService[label]) {
                trackingByService[label] = { count: 0, severity: req.severity, urls: [] };
            }
            trackingByService[label].count++;
            if (trackingByService[label].urls.length < 2) {
                trackingByService[label].urls.push(req.url);
            }
        }
    }

    return {
        url: TARGET,
        mode,
        timestamp: new Date().toISOString(),
        score,
        issues,
        checks,
        cookieBanner: results.banner ? {
            detected: results.banner.bannerFound,
            solution: results.banner.solution,
            buttons: results.banner.buttons,
            checkboxes: results.banner.checkboxes,
            legalLinks: results.banner.links,
        } : null,
        summary: {
            totalCookies: (pre?.cookies || []).length,
            trackingCookies: preTrackingCookies.length,
            trackingRequests: preTrackingCount,
            trackingServices: Object.keys(trackingByService),
            cookieBannerDetected: results.banner?.bannerFound ?? false,
            cookieBannerSolution: results.banner?.solution ? [results.banner.solution] : [],
            acceptFlowWorks: results.acceptAll ? (results.acceptAll.interact?.postTracking?.length || 0) > 0 : null,
            rejectFlowClean: results.reject ? (results.reject.interact?.postTracking?.length || 0) === 0 : null,
        },
        cookies: pre?.cookies || [],
        trackingByService,
        // Include full scenario data for detailed inspection
        scenarios: {
            preConsent: pre ? { preTracking: pre.preTracking, trackingCookies: pre.trackingCookies } : null,
            acceptAll: results.acceptAll?.interact || null,
            reject: results.reject?.interact || null,
        },
    };
}

// ── MAIN ──
async function main() {
    const chrome = findChrome();
    if (!chrome) {
        console.error(JSON.stringify({ success: false, error: 'Chrome/Chromium not found on this system. Install Google Chrome.' }));
        process.exit(1);
    }

    // IMPORTANT: Must use headless:false because GTM, GA4, Facebook SDK,
    // and Yandex Metrica detect headless Chrome and refuse to fire.
    // The Chrome window will briefly open during the audit.
    const browser = await puppeteer.launch({
        headless: false,
        executablePath: chrome,
        args: [
            '--no-sandbox',
            '--disable-setuid-sandbox',
            '--disable-dev-shm-usage',
            '--disable-blink-features=AutomationControlled',
            '--window-size=1280,800',
        ],
    });

    const results = {};
    let screenshotPath = null;

    try {
        // Screenshot capture: only for full mode (used by AI banner analysis)
        if (MODE === 'full') {
            try {
                const ssCtx = await browser.createBrowserContext();
                const ssPage = await ssCtx.newPage();
                await setupStealthPage(ssPage);
                const client = await ssPage.createCDPSession();
                await client.send('Network.clearBrowserCookies');
                await client.send('Network.clearBrowserCache');
                await ssPage.setViewport({ width: 1280, height: 800 });
                try { await ssPage.goto(TARGET, { waitUntil: 'load', timeout: 30000 }); } catch (e) { /* ok */ }
                await new Promise(r => setTimeout(r, 5000));
                screenshotPath = path.join(os.tmpdir(), `gdpr-screenshot-${Date.now()}.png`);
                await ssPage.screenshot({ path: screenshotPath, fullPage: false });
                await ssCtx.close();
            } catch (e) {
                screenshotPath = null; // Non-critical, continue without screenshot
            }
        }

        // Always run pre-consent
        results.preConsent = await scenario(browser, 'Pre-Consent', null);

        // Banner check: mechanical CSS-based detection (both modes)
        results.banner = await bannerCheck(browser);

        if (MODE === 'full') {
            // Full mode: also run accept/reject scenarios
            results.acceptAll = await scenario(browser, 'Accept-All', ACCEPT_PATTERNS);
            results.reject = await scenario(browser, 'Reject', REJECT_PATTERNS);
        }
    } catch (e) {
        console.error(JSON.stringify({ success: false, error: e.message }));
        await browser.close();
        process.exit(1);
    }

    await browser.close();

    // Build the report
    const report = buildReport(results, MODE);
    report.screenshotPath = screenshotPath;
    console.log(JSON.stringify({ success: true, data: report }));
}

main().catch(e => {
    console.error(JSON.stringify({ success: false, error: e.message }));
    process.exit(1);
});
