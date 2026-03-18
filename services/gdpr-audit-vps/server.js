const express = require('express');
const { execFile } = require('child_process');
const path = require('path');
const fs = require('fs');
const crypto = require('crypto');
const { generatePdf, closeBrowser } = require('./pdf-service');

const app = express();
app.use(express.json({ limit: '10mb' }));

// Simple API key auth
const API_KEY = process.env.GDPR_API_KEY || 'lsm-gdpr-audit-2026-secure-key';

function auth(req, res, next) {
    const key = req.headers['x-api-key'] || req.query.api_key;
    if (key !== API_KEY) return res.status(401).json({ error: 'Unauthorized' });
    next();
}

// ── In-memory job store for async audits ──
// Jobs are stored for 30 minutes then auto-cleaned
const jobs = new Map();
const JOB_TTL = 30 * 60 * 1000; // 30 minutes

function cleanupJobs() {
    const now = Date.now();
    for (const [id, job] of jobs) {
        if (now - job.createdAt > JOB_TTL) {
            jobs.delete(id);
        }
    }
}
setInterval(cleanupJobs, 5 * 60 * 1000); // clean every 5 min

// Helper: run an audit script in the background
function runAuditAsync(jobId, scriptPath, args, timeout, auditType) {
    const cmd = '/usr/bin/xvfb-run';
    const fullArgs = ['--auto-servernum', '--server-args=-screen 0 1280x800x24', process.execPath, scriptPath, ...args];

    console.log(`[${new Date().toISOString()}] ${auditType} started (job: ${jobId})`);

    execFile(cmd, fullArgs, {
        timeout,
        maxBuffer: 10 * 1024 * 1024,
        env: { ...process.env, DISPLAY: ':99' },
    }, (error, stdout, stderr) => {
        const job = jobs.get(jobId);
        if (!job) return; // job was already cleaned up

        if (error) {
            console.error(`[${new Date().toISOString()}] ${auditType} error (job: ${jobId}):`, error.message);
            try {
                const data = JSON.parse(stdout);
                job.status = 'completed';
                job.result = data;
            } catch (e) {
                job.status = 'failed';
                job.error = error.message;
            }
            return;
        }

        try {
            const data = JSON.parse(stdout);
            console.log(`[${new Date().toISOString()}] ${auditType} completed (job: ${jobId}) — score: ${data?.data?.score}`);

            // If there's a screenshot, read it and include as base64
            if (data?.data?.screenshotPath && fs.existsSync(data.data.screenshotPath)) {
                const screenshotBuffer = fs.readFileSync(data.data.screenshotPath);
                data.data.screenshotBase64 = screenshotBuffer.toString('base64');
                fs.unlinkSync(data.data.screenshotPath);
                delete data.data.screenshotPath;
            }

            job.status = 'completed';
            job.result = data;
        } catch (e) {
            console.error(`[${new Date().toISOString()}] Parse error (job: ${jobId}):`, e.message);
            job.status = 'failed';
            job.error = 'Failed to parse audit output';
        }
    });
}

app.get('/health', (req, res) => {
    res.json({ status: 'healthy', service: 'lsm-audit-service', timestamp: new Date().toISOString() });
});

// ── Generic PDF Generation ──
app.post('/generate-pdf', auth, async (req, res) => {
    const { template, data, options } = req.body;

    if (!template || !data) {
        return res.status(400).json({ error: 'template and data are required' });
    }

    console.log(`[${new Date().toISOString()}] PDF generation started: template=${template}`);

    try {
        const templatePath = path.join(__dirname, 'templates', `${template}.js`);
        if (!fs.existsSync(templatePath)) {
            return res.status(400).json({ error: `Template '${template}' not found` });
        }

        delete require.cache[require.resolve(templatePath)];
        const templateFn = require(templatePath);
        const html = templateFn(data);
        const pdfBuffer = await generatePdf(html, options || {});

        console.log(`[${new Date().toISOString()}] PDF generated: template=${template}, size=${pdfBuffer.length} bytes`);

        res.set({
            'Content-Type': 'application/pdf',
            'Content-Length': pdfBuffer.length,
            'Content-Disposition': `attachment; filename="${template}.pdf"`,
        });
        res.send(pdfBuffer);
    } catch (error) {
        console.error(`[${new Date().toISOString()}] PDF generation error:`, error.message);
        res.status(500).json({ error: 'PDF generation failed', message: error.message });
    }
});

// ── Audit Status (polling endpoint) ──
app.get('/audit-status/:jobId', auth, (req, res) => {
    const job = jobs.get(req.params.jobId);
    if (!job) {
        return res.status(404).json({ error: 'Job not found or expired' });
    }
    res.json({
        jobId: req.params.jobId,
        status: job.status,
        type: job.type,
        ...(job.status === 'completed' ? { result: job.result } : {}),
        ...(job.status === 'failed' ? { error: job.error } : {}),
    });
});

// ── GDPR Audit (async — returns jobId immediately) ──
app.post('/audit', auth, (req, res) => {
    const { url, mode = 'quick' } = req.body;
    if (!url) return res.status(400).json({ error: 'url is required' });

    const jobId = crypto.randomUUID();
    const scriptPath = path.join(__dirname, 'gdpr-audit.js');

    jobs.set(jobId, {
        status: 'processing',
        type: 'gdpr',
        url,
        mode,
        createdAt: Date.now(),
        result: null,
        error: null,
    });

    runAuditAsync(jobId, scriptPath, [url, '--mode=' + mode], mode === 'full' ? 180000 : 120000, `GDPR Audit: ${url} (${mode})`);

    // Return immediately
    res.json({
        status: 'processing',
        jobId,
        message: `GDPR ${mode} audit started. Poll /audit-status/${jobId} for results.`,
    });
});

// ── Accessibility Audit (async — returns jobId immediately) ──
app.post('/accessibility-audit', auth, (req, res) => {
    const { url } = req.body;
    if (!url) return res.status(400).json({ error: 'url is required' });

    const jobId = crypto.randomUUID();
    const scriptPath = path.join(__dirname, 'accessibility-audit.js');

    jobs.set(jobId, {
        status: 'processing',
        type: 'accessibility',
        url,
        createdAt: Date.now(),
        result: null,
        error: null,
    });

    runAuditAsync(jobId, scriptPath, [url], 120000, `Accessibility Audit: ${url}`);

    // Return immediately
    res.json({
        status: 'processing',
        jobId,
        message: `Accessibility audit started. Poll /audit-status/${jobId} for results.`,
    });
});

// Graceful shutdown
process.on('SIGTERM', async () => {
    console.log('Shutting down...');
    await closeBrowser();
    process.exit(0);
});

const PORT = process.env.PORT || 3100;
app.listen(PORT, '0.0.0.0', () => {
    console.log(`LSM Audit Service running on port ${PORT}`);
});
