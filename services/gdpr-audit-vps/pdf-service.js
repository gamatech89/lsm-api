/**
 * PDF Service — Generic Puppeteer PDF Generator
 * 
 * Reusable module that renders HTML to PDF using Puppeteer.
 * Used by the Express server's /generate-pdf endpoint.
 */

const puppeteer = require('puppeteer-core');

let browserInstance = null;

/**
 * Get or create a shared browser instance
 */
async function getBrowser() {
  if (browserInstance && browserInstance.connected) {
    return browserInstance;
  }

  browserInstance = await puppeteer.launch({
    executablePath: '/usr/bin/google-chrome-stable',
    headless: 'new',
    args: [
      '--no-sandbox',
      '--disable-setuid-sandbox',
      '--disable-dev-shm-usage',
      '--disable-gpu',
      '--disable-web-security',
      '--font-render-hinting=none',
    ],
  });

  browserInstance.on('disconnected', () => {
    browserInstance = null;
  });

  return browserInstance;
}

/**
 * Generate a PDF from an HTML string.
 * 
 * @param {string} html - Full HTML document string
 * @param {object} options - PDF options
 * @param {string} options.format - Page format (default: 'A4')
 * @param {boolean} options.landscape - Landscape orientation (default: false)  
 * @param {object} options.margin - Margins (default: { top: '20mm', bottom: '20mm', left: '15mm', right: '15mm' })
 * @param {boolean} options.printBackground - Print backgrounds (default: true)
 * @returns {Promise<Buffer>} PDF buffer
 */
async function generatePdf(html, options = {}) {
  const browser = await getBrowser();
  const page = await browser.newPage();

  try {
    // Set content and wait for fonts/styles to load
    await page.setContent(html, { 
      waitUntil: ['networkidle0', 'domcontentloaded'],
      timeout: 30000,
    });

    // Wait a bit for fonts to render
    await page.evaluate(() => document.fonts?.ready);

    const pdfBuffer = await page.pdf({
      format: options.format || 'A4',
      landscape: options.landscape || false,
      printBackground: options.printBackground !== false,
      margin: options.margin || {
        top: '0',
        bottom: '0',
        left: '0',
        right: '0',
      },
      preferCSSPageSize: true,
    });

    return pdfBuffer;
  } finally {
    await page.close();
  }
}

/**
 * Graceful shutdown
 */
async function closeBrowser() {
  if (browserInstance) {
    await browserInstance.close();
    browserInstance = null;
  }
}

module.exports = { generatePdf, closeBrowser };
