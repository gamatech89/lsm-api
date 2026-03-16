<?php

namespace App\Http\Controllers;

use App\Models\AccessibilityAuditReport;
use App\Models\MaintenanceReport;
use App\Models\Project;
use App\Services\AccessibilityAiService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class AccessibilityAuditController extends Controller
{
    public function __construct(private AccessibilityAiService $aiService)
    {
    }

    /**
     * Run an accessibility audit for a project using Puppeteer + axe-core + AI analysis.
     *
     * Pipeline:
     * 1. Puppeteer + axe-core collects violation data and screenshot
     * 2. AI analyzes screenshot for visual accessibility issues
     * 3. AI generates compliance summary
     * 4. Save report with ai_enhanced flag
     */
    public function runAudit(Project $project, Request $request): JsonResponse
    {
        $request->validate([
            'locale' => 'in:en,de',
        ]);

        if (!$project->url) {
            return response()->json([
                'success' => false,
                'message' => 'Project has no URL configured. A website URL is required for accessibility auditing.',
            ], 422);
        }

        set_time_limit(0);

        $locale = $request->input('locale', 'en');

        // ── Run the audit (remote microservice or local fallback) ──
        $serviceUrl = config('services.accessibility_audit.url');
        $serviceKey = config('services.accessibility_audit.key');

        if ($serviceUrl) {
            $auditData = $this->runRemoteAudit($serviceUrl, $serviceKey, $project->url);
        } else {
            $auditData = $this->runLocalAudit($project->url);
        }

        if (!$auditData) {
            return response()->json([
                'success' => false,
                'message' => 'Accessibility audit failed. Check server logs for details.',
            ], 500);
        }

        $screenshotPath = $auditData['screenshotPath'] ?? null;
        $aiEnhanced = false;

        // ── AI Enhancement Pipeline ──

        // Step 1: AI screenshot analysis for visual accessibility
        if ($screenshotPath && file_exists($screenshotPath)) {
            $aiScreenshot = $this->aiService->analyzeScreenshot($screenshotPath);
            if ($aiScreenshot) {
                $aiEnhanced = true;
                $auditData['aiScreenshot'] = $aiScreenshot;
                Log::info('Accessibility AI: Screenshot analysis succeeded', [
                    'url' => $project->url,
                    'visualIssues' => count($aiScreenshot['visualIssues'] ?? []),
                ]);
            }
        }

        // Step 2: AI compliance summary (locale-aware)
        $aiSummary = $this->aiService->generateSummary($auditData, $project->url, $locale);
        if ($aiSummary) {
            $aiEnhanced = true;
            $auditData['aiSummary'] = $aiSummary;
            Log::info('Accessibility AI: Summary generated', [
                'url' => $project->url,
                'score' => $aiSummary['score'] ?? null,
            ]);
        }

        // Clean up screenshot temp file
        if ($screenshotPath && file_exists($screenshotPath)) {
            @unlink($screenshotPath);
        }
        unset($auditData['screenshotPath']);

        $auditData['aiEnhanced'] = $aiEnhanced;

        // Save the audit report
        $report = AccessibilityAuditReport::create([
            'project_id' => $project->id,
            'audit_data' => $auditData,
        ]);

        return response()->json([
            'success' => true,
            'data' => $report,
        ]);
    }

    /**
     * Call the remote accessibility audit microservice.
     */
    private function runRemoteAudit(string $serviceUrl, string $serviceKey, string $url): ?array
    {
        try {
            $response = \Illuminate\Support\Facades\Http::timeout(120)
                ->withHeaders(['X-Api-Key' => $serviceKey])
                ->post(rtrim($serviceUrl, '/') . '/accessibility-audit', [
                    'url' => $url,
                ]);

            if (!$response->successful()) {
                Log::error('Accessibility remote audit failed', ['status' => $response->status(), 'body' => $response->body()]);
                return null;
            }

            $result = $response->json();
            if (!$result || !($result['success'] ?? false)) {
                Log::error('Accessibility remote audit returned error', ['result' => $result]);
                return null;
            }

            $auditData = $result['data'];

            // If microservice returned a base64 screenshot, save to temp file for AI
            if (!empty($auditData['screenshotBase64'])) {
                $tmpPath = sys_get_temp_dir() . '/a11y-screenshot-' . time() . '.png';
                file_put_contents($tmpPath, base64_decode($auditData['screenshotBase64']));
                $auditData['screenshotPath'] = $tmpPath;
                unset($auditData['screenshotBase64']);
            }

            return $auditData;
        } catch (\Throwable $e) {
            Log::error('Accessibility remote audit exception', ['message' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Run audit locally via Puppeteer process (dev environment).
     */
    private function runLocalAudit(string $url): ?array
    {
        $scriptPath = base_path('scripts/accessibility-audit.js');
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
            ? ['xvfb-run', '--auto-servernum', '--server-args=-screen 0 1280x800x24', $nodePath, $scriptPath, $url]
            : [$nodePath, $scriptPath, $url];

        $process = new Process($command);
        $process->setTimeout(120);
        $process->run();

        if (!$process->isSuccessful()) {
            Log::error('Accessibility local audit failed', ['error' => $process->getErrorOutput()]);
            return null;
        }

        $result = json_decode($process->getOutput(), true);
        return ($result && ($result['success'] ?? false)) ? $result['data'] : null;
    }

    /**
     * Get the latest accessibility audit report for a project.
     */
    public function getLatest(Project $project): JsonResponse
    {
        $report = AccessibilityAuditReport::where('project_id', $project->id)
            ->orderBy('created_at', 'desc')
            ->first();

        return response()->json([
            'success' => true,
            'data' => $report,
        ]);
    }

    /**
     * List all accessibility audit reports for a project.
     */
    public function index(Project $project, Request $request): JsonResponse
    {
        $reports = AccessibilityAuditReport::where('project_id', $project->id)
            ->orderBy('created_at', 'desc')
            ->limit($request->input('limit', 10))
            ->get();

        return response()->json([
            'success' => true,
            'data' => $reports,
        ]);
    }

    /**
     * Download the accessibility audit report as PDF.
     */
    public function downloadPdf(Project $project, AccessibilityAuditReport $report)
    {
        $auditData = $report->audit_data;
        $aiSummary = $auditData['aiSummary'] ?? [];
        $score = $aiSummary['score'] ?? $auditData['score'] ?? 0;

        $pdf = Pdf::loadView('pdf.accessibility-audit', [
            'projectName' => $project->name,
            'url' => $auditData['url'] ?? $project->url,
            'auditData' => $auditData,
            'aiSummary' => $aiSummary,
            'score' => $score,
            'verdict' => $aiSummary['verdict'] ?? ($score >= 80 ? 'Accessible' : ($score >= 50 ? 'Needs Improvement' : 'Critical Issues')),
            'wcagLevel' => $auditData['wcagLevel'] ?? 'AA',
            'generatedAt' => now()->format('F j, Y \a\t g:i A'),
        ]);

        $filename = sprintf(
            'accessibility-audit-%s-%s.pdf',
            \Illuminate\Support\Str::slug($project->name),
            $report->created_at->format('Y-m-d')
        );

        return $pdf->download($filename);
    }

    /**
     * Save the accessibility audit report as a PDF to the project's maintenance reports.
     */
    public function saveToReports(Project $project, AccessibilityAuditReport $report): JsonResponse
    {
        $auditData = $report->audit_data;
        $aiSummary = $auditData['aiSummary'] ?? [];
        $score = $aiSummary['score'] ?? $auditData['score'] ?? 0;

        $pdf = Pdf::loadView('pdf.accessibility-audit', [
            'projectName' => $project->name,
            'url' => $auditData['url'] ?? $project->url,
            'auditData' => $auditData,
            'aiSummary' => $aiSummary,
            'score' => $score,
            'verdict' => $aiSummary['verdict'] ?? ($score >= 80 ? 'Accessible' : ($score >= 50 ? 'Needs Improvement' : 'Critical Issues')),
            'wcagLevel' => $auditData['wcagLevel'] ?? 'AA',
            'generatedAt' => now()->format('F j, Y \a\t g:i A'),
        ]);

        // Save PDF to storage
        $filename = sprintf(
            'accessibility-audit-%s-%s.pdf',
            \Illuminate\Support\Str::slug($project->name),
            $report->created_at->format('Y-m-d-His')
        );
        $path = "maintenance-reports/{$project->id}/{$filename}";
        Storage::disk('local')->put($path, $pdf->output());

        // Create a maintenance report entry
        $maintenanceReport = MaintenanceReport::create([
            'project_id' => $project->id,
            'user_id' => auth()->id(),
            'report_date' => $report->created_at->toDateString(),
            'type' => 'ad-hoc',
            'summary' => sprintf(
                'Accessibility Audit (WCAG 2.1 %s) — Score: %d/100, %s. %s',
                $auditData['wcagLevel'] ?? 'AA',
                $score,
                $aiSummary['verdict'] ?? 'Basic scan',
                $aiSummary['summary'] ?? ''
            ),
            'pdf_path' => $path,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Accessibility audit saved to project reports',
            'data' => $maintenanceReport,
        ]);
    }
}
