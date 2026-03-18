<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\MaintenanceReportResource;
use App\Models\MaintenanceReport;
use App\Models\Project;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/**
 * Maintenance Report Controller
 * 
 * Manages project maintenance reports with PDF export.
 */
class MaintenanceReportController extends Controller
{
    /**
     * Display a listing of maintenance reports for a project.
     *
     * @param Project $project
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function index(Project $project)
    {
        Gate::authorize('view', $project);

        $reports = $project->maintenanceReports()
            ->with('user:id,name,email')
            ->orderBy('report_date', 'desc')
            ->get();

        return MaintenanceReportResource::collection($reports);
    }

    /**
     * Display the specified maintenance report.
     *
     * @param MaintenanceReport $maintenanceReport
     * @return MaintenanceReportResource
     */
    public function show(MaintenanceReport $maintenanceReport): MaintenanceReportResource
    {
        Gate::authorize('view', $maintenanceReport->project);

        $maintenanceReport->load('user:id,name,email');

        return new MaintenanceReportResource($maintenanceReport);
    }

    /**
     * Store a newly created maintenance report.
     *
     * @param Request $request
     * @param Project $project
     * @return JsonResponse
     */
    public function store(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('update', $project);

        $validated = $request->validate([
            'report_date' => 'required|date',
            'type' => 'required|in:monthly,weekly,ad-hoc',
            'summary' => 'nullable|string',
            'tasks_completed' => 'nullable|array',
            'tasks_completed.*' => 'string',
            'updates_performed' => 'nullable|array',
            'updates_performed.*.name' => 'required|string',
            'updates_performed.*.from_version' => 'nullable|string',
            'updates_performed.*.to_version' => 'nullable|string',
            'issues_found' => 'nullable|array',
            'issues_found.*' => 'string',
            'issues_resolved' => 'nullable|array',
            'issues_resolved.*' => 'string',
            'notes' => 'nullable|string',
            'time_spent_minutes' => 'nullable|integer|min:0',
            'invoice_id' => 'nullable|exists:invoices,id',
            'pdf_file' => 'nullable|file|mimes:pdf|max:20480', // Max 20MB
        ]);

        // Handle PDF file upload
        if ($request->hasFile('pdf_file')) {
            $path = $request->file('pdf_file')->store(
                "maintenance-reports/{$project->id}",
                'local'
            );
            $validated['pdf_path'] = $path;

            // Default summary if not provided for uploaded PDFs
            if (empty($validated['summary'])) {
                $validated['summary'] = 'Uploaded PDF report';
            }
        }

        // Ensure summary is set for classic reports
        if (empty($validated['summary']) && !isset($validated['pdf_path'])) {
            return response()->json(['message' => 'Summary is required for classic reports.'], 422);
        }

        unset($validated['pdf_file']);

        $validated['project_id'] = $project->id;
        $validated['user_id'] = auth()->id();

        $report = MaintenanceReport::create($validated);
        $report->load('user:id,name,email');

        return $this->createdResponse(
            new MaintenanceReportResource($report),
            'Maintenance report created successfully'
        );
    }

    /**
     * Update the specified maintenance report.
     *
     * @param Request $request
     * @param MaintenanceReport $maintenanceReport
     * @return MaintenanceReportResource
     */
    public function update(Request $request, MaintenanceReport $maintenanceReport): MaintenanceReportResource
    {
        Gate::authorize('update', $maintenanceReport->project);

        $validated = $request->validate([
            'report_date' => 'sometimes|required|date',
            'type' => 'sometimes|required|in:monthly,weekly,ad-hoc',
            'summary' => 'sometimes|required|string',
            'tasks_completed' => 'nullable|array',
            'updates_performed' => 'nullable|array',
            'issues_found' => 'nullable|array',
            'issues_resolved' => 'nullable|array',
            'notes' => 'nullable|string',
            'time_spent_minutes' => 'nullable|integer|min:0',
            'invoice_id' => 'nullable|exists:invoices,id',
        ]);

        $maintenanceReport->update($validated);
        $maintenanceReport->load('user:id,name,email');

        return new MaintenanceReportResource($maintenanceReport);
    }

    /**
     * Remove the specified maintenance report.
     *
     * @param MaintenanceReport $maintenanceReport
     * @return JsonResponse
     */
    public function destroy(MaintenanceReport $maintenanceReport): JsonResponse
    {
        Gate::authorize('update', $maintenanceReport->project);

        // Delete uploaded PDF file if it exists
        if ($maintenanceReport->pdf_path && Storage::disk('local')->exists($maintenanceReport->pdf_path)) {
            Storage::disk('local')->delete($maintenanceReport->pdf_path);
        }

        $maintenanceReport->delete();

        return $this->successResponse(null, 'Maintenance report deleted successfully');
    }

    /**
     * Download or view the maintenance report as PDF.
     * Use ?view=1 to open inline in browser instead of downloading.
     *
     * @param MaintenanceReport $maintenanceReport
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function downloadPdf(MaintenanceReport $maintenanceReport, Request $request)
    {
        Gate::authorize('view', $maintenanceReport->project);

        $disposition = $request->query('view') ? 'inline' : 'attachment';

        // If an uploaded PDF exists, serve it directly
        if ($maintenanceReport->pdf_path && Storage::disk('local')->exists($maintenanceReport->pdf_path)) {
            $filename = sprintf(
                'maintenance-report-%s-%s.pdf',
                $maintenanceReport->project?->name ?? $maintenanceReport->project_id,
                $maintenanceReport->report_date
            );

            if ($disposition === 'inline') {
                $content = Storage::disk('local')->get($maintenanceReport->pdf_path);
                return response($content)
                    ->header('Content-Type', 'application/pdf')
                    ->header('Content-Disposition', "inline; filename=\"{$filename}\"");
            }

            return Storage::disk('local')->download($maintenanceReport->pdf_path, $filename);
        }

        // Otherwise generate PDF from report data
        $maintenanceReport->load(['user:id,name,hourly_rate', 'project:id,name,url,project_external_id']);

        $pdf = Pdf::loadView('pdf.maintenance-report', [
            'report' => $maintenanceReport,
        ]);

        $filename = sprintf(
            'maintenance-report-%s-%s.pdf',
            $maintenanceReport->project->name,
            $maintenanceReport->report_date
        );

        if ($disposition === 'inline') {
            return $pdf->stream($filename);
        }
        return $pdf->download($filename);
    }

    /**
     * Get autocomplete suggestions for maintenance report fields.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function suggestions(Request $request): JsonResponse
    {
        $user = $request->user();

        // Get unique values from recent reports
        $recentReports = MaintenanceReport::where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get();

        $tasks = $recentReports->pluck('tasks_completed')->flatten()->filter()->unique()->values();
        $issues = $recentReports->pluck('issues_found')->flatten()->filter()->unique()->values();

        return $this->successResponse([
            'tasks' => $tasks,
            'issues' => $issues,
        ]);
    }
}
