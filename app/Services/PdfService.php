<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * PDF Generation Service
 * 
 * Generic service for generating PDFs via the VPS Puppeteer microservice.
 * Templates are JS modules on the VPS at /opt/gdpr-audit-service/templates/.
 * 
 * Usage:
 *   $pdfService = app(PdfService::class);
 *   $pdfBinary = $pdfService->generate('gdpr-audit', $data);
 *   return response($pdfBinary)->header('Content-Type', 'application/pdf');
 */
class PdfService
{
    private ?string $serviceUrl;
    private string $apiKey;

    public function __construct()
    {
        $this->serviceUrl = config('services.pdf_service.url');
        $this->apiKey = config('services.pdf_service.key', 'lsm-gdpr-audit-2026-secure-key');
    }

    /**
     * Generate a PDF from a template and data.
     *
     * @param string $template Template name (e.g. 'gdpr-audit', 'accessibility-audit')
     * @param array $data Data to pass to the template
     * @param array $options PDF options (format, landscape, margin, etc.)
     * @return string|null PDF binary content, or null on failure
     */
    public function generate(string $template, array $data, array $options = []): ?string
    {
        if (!$this->serviceUrl) {
            Log::warning('PdfService: No service URL configured (PDF_SERVICE_URL)');
            return null;
        }

        try {
            $response = Http::timeout(60)
                ->withHeaders([
                    'X-Api-Key' => $this->apiKey,
                    'Content-Type' => 'application/json',
                ])
                ->post(rtrim($this->serviceUrl, '/') . '/generate-pdf', [
                    'template' => $template,
                    'data' => $data,
                    'options' => $options,
                ]);

            if (!$response->successful()) {
                Log::error('PdfService: Generation failed', [
                    'template' => $template,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return null;
            }

            return $response->body();
        } catch (\Throwable $e) {
            Log::error('PdfService: Exception', [
                'template' => $template,
                'message' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Check if the PDF service is available.
     */
    public function isAvailable(): bool
    {
        return !empty($this->serviceUrl);
    }
}
