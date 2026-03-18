# LSM Audit VPS Service

Express API service running on a dedicated VPS for GDPR and Accessibility audits using Puppeteer.

## VPS Details

- **Host:** `148.230.70.231`
- **Port:** `3100`
- **Service path:** `/opt/gdpr-audit-service/`
- **Systemd unit:** `gdpr-audit.service`

## Files

| File | Description |
|------|-------------|
| `server.js` | Express API server (routes, auth, async job queue) |
| `gdpr-audit.js` | Puppeteer-based GDPR compliance scanner |
| `accessibility-audit.js` | Puppeteer-based accessibility scanner |
| `pdf-service.js` | Shared Puppeteer PDF generator module |
| `templates/gdpr-audit.js` | HTML template for GDPR audit PDF reports |
| `package.json` | Node.js dependencies |

## API Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/health` | Health check |
| `POST` | `/audit` | Start GDPR audit (async, returns `jobId`) |
| `POST` | `/accessibility-audit` | Start accessibility audit (async) |
| `GET` | `/audit-status/:jobId` | Poll for audit result |
| `POST` | `/generate-pdf` | Generate PDF from HTML template |

All `POST`/`GET` endpoints (except `/health`) require `X-Api-Key` header.

## Deployment

```bash
# Upload all files
scp server.js gdpr-audit.js accessibility-audit.js pdf-service.js root@148.230.70.231:/opt/gdpr-audit-service/
scp templates/gdpr-audit.js root@148.230.70.231:/opt/gdpr-audit-service/templates/

# If package.json changed, also install deps on VPS
scp package.json root@148.230.70.231:/opt/gdpr-audit-service/
ssh root@148.230.70.231 "cd /opt/gdpr-audit-service && npm install --production"

# Restart service
ssh root@148.230.70.231 "systemctl restart gdpr-audit && systemctl status gdpr-audit"
```

## Logs

```bash
ssh root@148.230.70.231 "journalctl -u gdpr-audit --no-pager -n 30"
```
