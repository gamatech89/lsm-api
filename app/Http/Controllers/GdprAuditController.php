<?php

namespace App\Http\Controllers;

use App\Models\GdprAuditReport;
use App\Models\MaintenanceReport;
use App\Models\Project;
use App\Services\GdprAiService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class GdprAuditController extends Controller
{
    public function __construct(private GdprAiService $aiService)
    {
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
        ]);

        if (!$project->url) {
            return response()->json([
                'success' => false,
                'message' => 'Project has no URL configured. A website URL is required for GDPR auditing.',
            ], 422);
        }

        set_time_limit(0);

        $mode = $request->input('mode', 'quick');
        $scriptPath = base_path('scripts/gdpr-audit.js');
        $nodePath = trim(shell_exec('which node') ?: 'node');

        // On Linux servers, wrap with xvfb-run to provide a virtual display.
        $isLinux = PHP_OS_FAMILY === 'Linux';
        $command = $isLinux
            ? ['xvfb-run', '--auto-servernum', '--server-args=-screen 0 1280x800x24', $nodePath, $scriptPath, $project->url, "--mode={$mode}"]
            : [$nodePath, $scriptPath, $project->url, "--mode={$mode}"];

        $process = new Process($command);
        $process->setTimeout($mode === 'full' ? 180 : 120);
        $process->run();

        if (!$process->isSuccessful()) {
            $errorOutput = $process->getErrorOutput() ?: $process->getOutput();
            $decoded = json_decode($errorOutput, true);

            return response()->json([
                'success' => false,
                'message' => $decoded['error'] ?? 'GDPR audit script failed. Ensure Node.js and Chrome are installed.',
                'raw_error' => $errorOutput,
            ], 500);
        }

        $output = $process->getOutput();
        $result = json_decode($output, true);

        if (!$result || !($result['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => $result['error'] ?? 'Failed to parse audit results.',
            ], 500);
        }

        $auditData = $result['data'];
        $screenshotPath = $auditData['screenshotPath'] ?? null;
        $aiEnhanced = false;
        $aiSummary = null;
        $aiBanner = null;

        // ── AI Enhancement Pipeline ──

        // Step 1: AI banner analysis from screenshot
        if ($screenshotPath && file_exists($screenshotPath)) {
            $aiBanner = $this->aiService->analyzeBanner($screenshotPath);
            if ($aiBanner) {
                $aiEnhanced = true;
                $auditData['aiBanner'] = $aiBanner;
                Log::info('GDPR AI: Banner analysis succeeded', [
                    'url' => $project->url,
                    'bannerFound' => $aiBanner['bannerFound'] ?? false,
                    'solution' => $aiBanner['solution'] ?? null,
                ]);
            }
        }

        // Step 2: AI compliance summary
        $aiSummary = $this->aiService->generateSummary($auditData, $project->url);
        if ($aiSummary) {
            $aiEnhanced = true;
            $auditData['aiSummary'] = $aiSummary;
            Log::info('GDPR AI: Summary generated', [
                'url' => $project->url,
                'score' => $aiSummary['score'] ?? null,
                'verdict' => $aiSummary['verdict'] ?? null,
            ]);
        }

        // Clean up screenshot temp file
        if ($screenshotPath && file_exists($screenshotPath)) {
            @unlink($screenshotPath);
        }

        // Remove screenshotPath from stored data (it's a temp file path)
        unset($auditData['screenshotPath']);

        // Mark as AI-enhanced
        $auditData['aiEnhanced'] = $aiEnhanced;

        // Save the audit report
        $report = GdprAuditReport::create([
            'project_id' => $project->id,
            'audit_data' => $auditData,
        ]);

        return response()->json([
            'success' => true,
            'data' => $report,
        ]);
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

        $pdf = Pdf::loadView('pdf.gdpr-audit', [
            'projectName' => $project->name,
            'url' => $auditData['url'] ?? $project->url,
            'auditData' => $auditData,
            'aiSummary' => $aiSummary,
            'score' => $score,
            'verdict' => $aiSummary['verdict'] ?? ($score >= 80 ? 'Good' : ($score >= 50 ? 'Needs Improvement' : 'Critical Issues')),
            'auditMode' => $auditData['mode'] ?? 'quick',
            'generatedAt' => now()->format('F j, Y \a\t g:i A'),
        ]);

        $filename = sprintf(
            'gdpr-audit-%s-%s.pdf',
            \Illuminate\Support\Str::slug($project->name),
            $report->created_at->format('Y-m-d')
        );

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

        $pdf = Pdf::loadView('pdf.gdpr-audit', [
            'projectName' => $project->name,
            'url' => $auditData['url'] ?? $project->url,
            'auditData' => $auditData,
            'aiSummary' => $aiSummary,
            'score' => $score,
            'verdict' => $aiSummary['verdict'] ?? ($score >= 80 ? 'Good' : ($score >= 50 ? 'Needs Improvement' : 'Critical Issues')),
            'auditMode' => $auditData['mode'] ?? 'quick',
            'generatedAt' => now()->format('F j, Y \a\t g:i A'),
        ]);

        // Save PDF to storage
        $filename = sprintf(
            'gdpr-audit-%s-%s.pdf',
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
