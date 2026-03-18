<?php

namespace App\Http\Controllers;

use App\Models\GdprAuditReport;
use App\Models\MaintenanceReport;
use App\Models\Project;
use App\Services\GdprAiService;
use App\Services\PdfService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class GdprAuditController extends Controller
{
    public function __construct(
        private GdprAiService $aiService,
        private PdfService $pdfService,
    ) {
    }

    /**
     * Run a GDPR audit for a project using Puppeteer + AI analysis.
     *
     * Pipeline:
     * 1. Puppeteer collects raw data (tracking, cookies, banner, screenshot)
     * 2. AI analyzes screenshot for banner detection (fallback: mechanical)
     * 3. AI generates compliance summary (fallback: static scoring)
     * 4. Save report with ai_enhanced flag
     */
    public function runAudit(Project $project, Request $request): JsonResponse
    {
        $request->validate([
            'mode' => 'in:quick,full',
            'locale' => 'in:en,de',
        ]);

        if (!$project->url) {
            return response()->json([
                'success' => false,
                'message' => 'Project has no URL configured. A website URL is required for GDPR auditing.',
            ], 422);
        }

        $mode = $request->input('mode', 'quick');
        $locale = $request->input('locale', 'en');

        // ── Dispatch async audit ──
        $serviceUrl = config('services.gdpr_audit.url');
        $serviceKey = config('services.gdpr_audit.key');

        if ($serviceUrl) {
            // Production: start async audit on VPS
            $jobId = $this->startRemoteAudit($serviceUrl, $serviceKey, $project->url, $mode);

            if (!$jobId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to start GDPR audit. The audit service may be unavailable.',
                ], 500);
            }

            return response()->json([
                'success' => true,
                'status' => 'processing',
                'jobId' => $jobId,
                'message' => 'Audit started. Poll the status endpoint for results.',
            ]);
        }

        // Local dev: run synchronously (no timeout issue locally)
        set_time_limit(0);
        $auditData = $this->runLocalAudit($project->url, $mode);

        if (!$auditData) {
            return response()->json([
                'success' => false,
                'message' => 'GDPR audit failed. Check server logs for details.',
            ], 500);
        }

        $auditData = $this->enhanceWithAi($auditData, $project->url, $locale, $mode);

        $report = GdprAuditReport::create([
            'project_id' => $project->id,
            'audit_data' => $auditData,
        ]);

        return response()->json([
            'success' => true,
            'status' => 'completed',
            'data' => $report,
        ]);
    }

    /**
     * Check the status of an async audit job.
     * Called by the frontend via polling.
     */
    public function checkAuditStatus(Project $project, Request $request): JsonResponse
    {
        $request->validate([
            'jobId' => 'required|string',
            'locale' => 'in:en,de',
        ]);

        $jobId = $request->input('jobId');
        $locale = $request->input('locale', 'en');

        $serviceUrl = config('services.gdpr_audit.url');
        $serviceKey = config('services.gdpr_audit.key');

        if (!$serviceUrl) {
            return response()->json(['success' => false, 'message' => 'Audit service not configured'], 500);
        }

        try {
            $response = \Illuminate\Support\Facades\Http::timeout(15)
                ->withHeaders(['X-Api-Key' => $serviceKey])
                ->get(rtrim($serviceUrl, '/') . '/audit-status/' . $jobId);

            if (!$response->successful()) {
                return response()->json([
                    'success' => false,
                    'status' => 'failed',
                    'message' => 'Failed to check audit status',
                ], $response->status());
            }

            $data = $response->json();

            if ($data['status'] === 'processing') {
                return response()->json([
                    'success' => true,
                    'status' => 'processing',
                ]);
            }

            if ($data['status'] === 'failed') {
                return response()->json([
                    'success' => false,
                    'status' => 'failed',
                    'message' => $data['error'] ?? 'Audit failed on the remote service.',
                ]);
            }

            // status === 'completed' — process the result
            $result = $data['result'] ?? null;
            if (!$result || !($result['success'] ?? false)) {
                return response()->json([
                    'success' => false,
                    'status' => 'failed',
                    'message' => 'Audit completed but returned invalid data.',
                ]);
            }

            $auditData = $result['data'];

            // Handle base64 screenshot for AI
            if (!empty($auditData['screenshotBase64'])) {
                $tmpPath = sys_get_temp_dir() . '/gdpr-screenshot-' . time() . '.png';
                file_put_contents($tmpPath, base64_decode($auditData['screenshotBase64']));
                $auditData['screenshotPath'] = $tmpPath;
                unset($auditData['screenshotBase64']);
            }

            // Try AI enhancement but don't let it block — timeout after 20s
            set_time_limit(60);
            try {
                $auditData = $this->enhanceWithAi($auditData, $project->url, $locale, $auditData['mode'] ?? 'quick');
            } catch (\Throwable $e) {
                Log::warning('GDPR AI enhancement failed, saving without AI', ['error' => $e->getMessage()]);
                $auditData['aiEnhanced'] = false;
            }

            // Save the report
            $report = GdprAuditReport::create([
                'project_id' => $project->id,
                'audit_data' => $auditData,
            ]);

            return response()->json([
                'success' => true,
                'status' => 'completed',
                'data' => $report,
            ]);

        } catch (\Throwable $e) {
            Log::error('GDPR audit status check failed', ['message' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'status' => 'failed',
                'message' => 'Failed to check audit status: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * AI enhancement pipeline: banner analysis + compliance summary.
     */
    private function enhanceWithAi(array $auditData, string $url, string $locale, string $mode = 'quick'): array
    {
        $screenshotPath = $auditData['screenshotPath'] ?? null;
        $aiEnhanced = false;

        // Step 1: AI banner analysis from screenshot (full mode only — quick uses mechanical detection)
        if ($mode === 'full' && $screenshotPath && file_exists($screenshotPath)) {
            $aiBanner = $this->aiService->analyzeBanner($screenshotPath);
            if ($aiBanner) {
                $aiEnhanced = true;
                $auditData['aiBanner'] = $aiBanner;
                Log::info('GDPR AI: Banner analysis succeeded', [
                    'url' => $url,
                    'bannerFound' => $aiBanner['bannerFound'] ?? false,
                ]);

                // Merge AI result into mechanical data when AI detects a banner but mechanical didn't
                $mechanicalDetected = $auditData['cookieBanner']['detected'] ?? $auditData['summary']['cookieBannerDetected'] ?? false;
                if (($aiBanner['bannerFound'] ?? false) && !$mechanicalDetected) {
                    Log::info('GDPR AI: Overriding mechanical banner detection with AI result', ['url' => $url]);

                    // Override cookieBanner
                    $auditData['cookieBanner'] = array_merge($auditData['cookieBanner'] ?? [], [
                        'detected' => true,
                        'solution' => $aiBanner['solution'] ?? 'Custom',
                        'buttons' => $aiBanner['buttons'] ?? [],
                        'aiOverride' => true,
                    ]);

                    // Override summary
                    if (isset($auditData['summary'])) {
                        $auditData['summary']['cookieBannerDetected'] = true;
                        $auditData['summary']['cookieBannerSolution'] = [$aiBanner['solution'] ?? 'Custom'];
                    }

                    // Recalculate score: remove the -20 penalty for missing banner
                    if (isset($auditData['score'])) {
                        $auditData['score'] = min(100, $auditData['score'] + 20);
                    }

                    // Update checks to reflect AI-detected banner
                    if (isset($auditData['checks'])) {
                        foreach ($auditData['checks'] as &$check) {
                            if (($check['name'] ?? '') === 'Cookie Consent Banner') {
                                $check['status'] = 'pass';
                                $check['severity'] = 'ok';
                                $check['details'] = 'Cookie consent banner detected (AI): ' . ($aiBanner['solution'] ?? 'Custom');
                            }
                        }
                        unset($check);
                    }

                    // Remove "No cookie consent banner detected" from issues
                    if (isset($auditData['issues'])) {
                        $auditData['issues'] = array_values(array_filter($auditData['issues'], function ($issue) {
                            return stripos($issue, 'cookie consent banner') === false;
                        }));
                    }
                }
            }
        }

        // Step 2: AI compliance summary (locale-aware)
        $aiSummary = $this->aiService->generateSummary($auditData, $url, $locale);
        if ($aiSummary) {
            $aiEnhanced = true;
            $auditData['aiSummary'] = $aiSummary;
            Log::info('GDPR AI: Summary generated', [
                'url' => $url,
                'score' => $aiSummary['score'] ?? null,
            ]);
        }

        // Clean up screenshot temp file
        if ($screenshotPath && file_exists($screenshotPath)) {
            @unlink($screenshotPath);
        }
        unset($auditData['screenshotPath']);

        $auditData['aiEnhanced'] = $aiEnhanced;

        return $auditData;
    }

    /**
     * Start an async GDPR audit on the remote microservice.
     * Returns the jobId, or null on failure.
     */
    private function startRemoteAudit(string $serviceUrl, string $serviceKey, string $url, string $mode): ?string
    {
        try {
            $response = \Illuminate\Support\Facades\Http::timeout(15)
                ->withHeaders(['X-Api-Key' => $serviceKey])
                ->post(rtrim($serviceUrl, '/') . '/audit', [
                    'url' => $url,
                    'mode' => $mode,
                ]);

            if (!$response->successful()) {
                Log::error('GDPR remote audit start failed', ['status' => $response->status(), 'body' => $response->body()]);
                return null;
            }

            $result = $response->json();
            return $result['jobId'] ?? null;
        } catch (\Throwable $e) {
            Log::error('GDPR remote audit start exception', ['message' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Run audit locally via Puppeteer process (dev environment).
     */
    private function runLocalAudit(string $url, string $mode): ?array
    {
        $scriptPath = base_path('scripts/gdpr-audit.js');
        $nodePath = 'node';
        try {
            $which = new Process(['which', 'node']);
            $which->run();
            if ($which->isSuccessful()) {
                $nodePath = trim($which->getOutput()) ?: 'node';
            }
        } catch (\Throwable $e) {
            // fallback
        }

        $isLinux = PHP_OS_FAMILY === 'Linux';
        $command = $isLinux
            ? ['xvfb-run', '--auto-servernum', '--server-args=-screen 0 1280x800x24', $nodePath, $scriptPath, $url, "--mode={$mode}"]
            : [$nodePath, $scriptPath, $url, "--mode={$mode}"];

        $process = new Process($command);
        $process->setTimeout($mode === 'full' ? 180 : 60);
        $process->run();

        if (!$process->isSuccessful()) {
            Log::error('GDPR local audit failed', ['error' => $process->getErrorOutput()]);
            return null;
        }

        $result = json_decode($process->getOutput(), true);
        return ($result && ($result['success'] ?? false)) ? $result['data'] : null;
    }

    /**
     * Get the latest GDPR audit report for a project.
     */
    public function getLatest(Project $project): JsonResponse
    {
        $report = GdprAuditReport::where('project_id', $project->id)
            ->orderBy('created_at', 'desc')
            ->first();

        return response()->json([
            'success' => true,
            'data' => $report,
        ]);
    }

    /**
     * List all GDPR audit reports for a project.
     */
    public function index(Project $project, Request $request): JsonResponse
    {
        $reports = GdprAuditReport::where('project_id', $project->id)
            ->orderBy('created_at', 'desc')
            ->limit($request->input('limit', 10))
            ->get();

        return response()->json([
            'success' => true,
            'data' => $reports,
        ]);
    }

    /**
     * Download the GDPR audit report as PDF.
     */
    public function downloadPdf(Project $project, GdprAuditReport $report)
    {
        $auditData = $report->audit_data;
        $aiSummary = $auditData['aiSummary'] ?? [];
        $score = $aiSummary['score'] ?? $auditData['score'] ?? 0;

        $templateData = [
            'projectName' => $project->name,
            'url' => $auditData['url'] ?? $project->url,
            'auditData' => $auditData,
            'aiSummary' => $aiSummary,
            'score' => $score,
            'verdict' => $aiSummary['verdict'] ?? ($score >= 80 ? 'Good' : ($score >= 50 ? 'Needs Improvement' : 'Critical Issues')),
            'auditMode' => $auditData['mode'] ?? 'quick',
            'generatedAt' => now()->format('F j, Y \a\t g:i A'),
        ];

        $filename = sprintf(
            'gdpr-audit-%s-%s.pdf',
            \Illuminate\Support\Str::slug($project->name),
            $report->created_at->format('Y-m-d')
        );

        // Try Puppeteer PDF service first
        $pdfBinary = $this->pdfService->generate('gdpr-audit', $templateData);

        if ($pdfBinary) {
            return response($pdfBinary)
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', "attachment; filename=\"{$filename}\"");
        }

        // Fallback to DomPDF
        Log::warning('PdfService unavailable for GDPR audit, falling back to DomPDF');
        $pdf = Pdf::loadView('pdf.gdpr-audit', $templateData);
        return $pdf->download($filename);
    }

    /**
     * Save the GDPR audit report as a PDF to the project's maintenance reports.
     */
    public function saveToReports(Project $project, GdprAuditReport $report): JsonResponse
    {
        $auditData = $report->audit_data;
        $aiSummary = $auditData['aiSummary'] ?? [];
        $score = $aiSummary['score'] ?? $auditData['score'] ?? 0;

        $templateData = [
            'projectName' => $project->name,
            'url' => $auditData['url'] ?? $project->url,
            'auditData' => $auditData,
            'aiSummary' => $aiSummary,
            'score' => $score,
            'verdict' => $aiSummary['verdict'] ?? ($score >= 80 ? 'Good' : ($score >= 50 ? 'Needs Improvement' : 'Critical Issues')),
            'auditMode' => $auditData['mode'] ?? 'quick',
            'generatedAt' => now()->format('F j, Y \a\t g:i A'),
        ];

        // Try Puppeteer PDF service first
        $pdfContent = $this->pdfService->generate('gdpr-audit', $templateData);

        if (!$pdfContent) {
            // Fallback to DomPDF
            Log::warning('PdfService unavailable for GDPR saveToReports, falling back to DomPDF');
            $pdf = Pdf::loadView('pdf.gdpr-audit', $templateData);
            $pdfContent = $pdf->output();
        }

        // Save PDF to storage
        $filename = sprintf(
            'gdpr-audit-%s-%s.pdf',
            \Illuminate\Support\Str::slug($project->name),
            $report->created_at->format('Y-m-d-His')
        );
        $path = "maintenance-reports/{$project->id}/{$filename}";
        Storage::disk('local')->put($path, $pdfContent);

        // Create a maintenance report entry
        $maintenanceReport = MaintenanceReport::create([
            'project_id' => $project->id,
            'user_id' => auth()->id(),
            'report_date' => $report->created_at->toDateString(),
            'type' => 'ad-hoc',
            'summary' => sprintf(
                'GDPR Compliance Audit — Score: %d/100, %s. %s',
                $score,
                $aiSummary['verdict'] ?? 'Basic scan',
                $aiSummary['summary'] ?? ''
            ),
            'pdf_path' => $path,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'GDPR audit saved to project reports',
            'data' => $maintenanceReport,
        ]);
    }
}
