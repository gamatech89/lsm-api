<?php

namespace App\Mcp\Tools;

use App\Models\Project;
use App\Models\User;
use App\Models\Todo;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class GeneratePdfTool extends Tool
{
    protected string $name = 'generate-pdf';

    protected string $description = <<<'MARKDOWN'
        Generate a PDF document from project data. Available report types:
        - team: List of all team members with their roles
        - projects: List of projects (use filter for subset)
        - project-details: Detailed info about a specific project
        - todos: List of todos (optionally filtered by project)
        
        Use 'filter' parameter to create filtered reports like:
        - missing_url: Projects without URL/external ID
        - missing_manager: Projects without a PM assigned
        - at_risk: Projects with security_status = at_risk
        - offline: Projects with health_status = down_error
    MARKDOWN;

    public function handle(Request $request): Response
    {
        $user = Auth::user();
        $input = $request->all();

        $reportType = $input['report_type'] ?? 'team';
        $projectId = $input['project_id'] ?? null;
        $filter = $input['filter'] ?? null;

        try {
            $data = [];
            $title = '';
            $template = '';

            switch ($reportType) {
                case 'team':
                    $data = $this->getTeamData();
                    $title = 'Team Members Report';
                    $template = 'pdfs.team';
                    break;

                case 'projects':
                    $data = $this->getProjectsData($filter);
                    $title = $this->getProjectsTitle($filter);
                    $template = 'pdfs.projects';
                    break;

                case 'project-details':
                    if (!$projectId) {
                        return Response::error('project_id is required for project-details report.');
                    }
                    $data = $this->getProjectDetailsData($projectId);
                    if (!$data) {
                        return Response::error("Project with ID {$projectId} not found.");
                    }
                    $title = "Project Report: {$data['project']->name}";
                    $template = 'pdfs.project-details';
                    break;

                case 'todos':
                    $data = $this->getTodosData($projectId, $filter);
                    $title = $this->getTodosTitle($projectId, $filter);
                    $template = 'pdfs.todos';
                    break;

                default:
                    return Response::error("Unknown report type: {$reportType}. Use: team, projects, project-details, todos");
            }

            if (isset($data['projects']) && $data['projects']->isEmpty()) {
                return Response::error("No projects found matching the filter: {$filter}");
            }

            // Generate PDF
            $pdf = Pdf::loadView($template, [
                'data' => $data,
                'title' => $title,
                'generatedAt' => now()->format('Y-m-d H:i'),
                'generatedBy' => $user->name,
            ]);

            // Save to storage
            $filename = str_replace([' ', ':'], ['_', '-'], strtolower($title)) . '_' . now()->format('Ymd_His') . '.pdf';
            $path = "pdfs/{$filename}";
            
            Storage::disk('public')->put($path, $pdf->output());
            
            $downloadUrl = url('/storage/' . $path);

            $count = '';
            if (isset($data['projects'])) {
                $count = "**Count**: {$data['projects']->count()} projects\n";
            } elseif (isset($data['todos'])) {
                $count = "**Count**: {$data['todos']->count()} todos\n";
            } elseif (isset($data['members'])) {
                $count = "**Count**: {$data['members']->count()} members\n";
            }

            return Response::text(
                "✅ **PDF Generated Successfully**\n\n" .
                "**Report**: {$title}\n" .
                $count .
                "**Generated**: " . now()->format('Y-m-d H:i') . "\n\n" .
                "📥 **Download Link**: {$downloadUrl}\n\n" .
                "_Link is valid for 24 hours._"
            );

        } catch (\Exception $e) {
            return Response::error('Failed to generate PDF: ' . $e->getMessage());
        }
    }

    private function getTeamData(): array
    {
        return [
            'members' => User::orderBy('role')
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'role', 'created_at'])
        ];
    }

    private function getProjectsData(?string $filter = null): array
    {
        $query = Project::with('manager:id,name')->orderBy('name');

        // Apply filters
        switch ($filter) {
            case 'missing_url':
            case 'missing_external_id':
            case 'no_url':
                $query->where(function ($q) {
                    $q->whereNull('url')
                      ->orWhere('url', '=', '')
                      ->orWhere('url', 'like', 'http://localhost%');
                });
                break;
            case 'missing_manager':
            case 'no_manager':
            case 'no_pm':
                $query->whereNull('manager_id');
                break;
            case 'at_risk':
            case 'at-risk':
            case 'risk':
                $query->whereIn('security_status', ['compromised', 'hacked']);
                break;
            case 'compromised':
            case 'hacked':
                $query->whereIn('security_status', ['compromised', 'hacked']);
                break;
            case 'offline':
            case 'down':
                $query->where('health_status', 'down_error');
                break;
            case 'maintenance':
                $query->where('is_maintenance', true);
                break;
            case 'connected':
            case 'has_wordpress':
                $query->whereNotNull('health_check_secret')
                      ->where('health_check_secret', '!=', '');
                break;
            case 'not_connected':
            case 'no_wordpress':
                $query->where(function ($q) {
                    $q->whereNull('health_check_secret')
                      ->orWhere('health_check_secret', '=', '');
                });
                break;
        }

        return [
            'projects' => $query->get(['id', 'name', 'url', 'health_status', 'security_status', 'manager_id'])
        ];
    }

    private function getProjectsTitle(?string $filter): string
    {
        $titles = [
            'missing_url' => 'Projects Missing URL/External ID',
            'missing_external_id' => 'Projects Missing External ID',
            'no_url' => 'Projects Missing URL',
            'missing_manager' => 'Projects Without Manager',
            'no_manager' => 'Projects Without Manager',
            'no_pm' => 'Projects Without PM',
            'at_risk' => 'At-Risk Projects',
            'compromised' => 'Compromised/Hacked Projects',
            'hacked' => 'Hacked Projects',
            'offline' => 'Offline Projects',
            'down' => 'Down Projects',
            'maintenance' => 'Projects in Maintenance',
            'connected' => 'WP Connected Projects',
            'has_wordpress' => 'WordPress Connected',
            'not_connected' => 'WP Not Connected',
            'no_wordpress' => 'No WordPress Connection',
        ];

        return $titles[$filter] ?? 'All Projects Report';
    }

    private function getProjectDetailsData($projectId): ?array
    {
        $project = Project::with(['manager', 'developers', 'todos' => function ($q) {
            $q->orderBy('priority', 'desc')->limit(20);
        }])->find($projectId);

        if (!$project) return null;

        return [
            'project' => $project,
        ];
    }

    private function getTodosData($projectId = null, ?string $filter = null): array
    {
        $query = Todo::with(['project:id,name', 'assignee:id,name'])
            ->orderBy('priority', 'desc')
            ->orderBy('created_at', 'desc');

        if ($projectId) {
            $query->where('project_id', $projectId);
        }

        // Apply todo filters
        switch ($filter) {
            case 'urgent':
                $query->where('priority', 'urgent');
                break;
            case 'high':
                $query->whereIn('priority', ['urgent', 'high']);
                break;
            case 'pending':
                $query->where('status', 'pending');
                break;
            case 'completed':
                $query->where('status', 'completed');
                break;
            case 'overdue':
                $query->where('due_date', '<', now())->where('status', '!=', 'completed');
                break;
            case 'unassigned':
                $query->whereNull('assignee_id');
                break;
        }

        return [
            'todos' => $query->limit(100)->get()
        ];
    }

    private function getTodosTitle($projectId, ?string $filter): string
    {
        $base = $projectId ? "Project {$projectId} Todos" : 'All Todos';
        
        $suffixes = [
            'urgent' => ' (Urgent)',
            'high' => ' (High Priority)',
            'pending' => ' (Pending)',
            'completed' => ' (Completed)',
            'overdue' => ' (Overdue)',
            'unassigned' => ' (Unassigned)',
        ];

        return $base . ($suffixes[$filter] ?? '');
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'report_type' => $schema->string()
                ->description('Type of report: team, projects, project-details, todos')
                ->enum(['team', 'projects', 'project-details', 'todos'])
                ->required(),
            'project_id' => $schema->integer()
                ->description('Project ID (required for project-details, optional for todos)'),
            'filter' => $schema->string()
                ->description('Filter for projects: missing_url, missing_manager, at_risk, offline, maintenance, connected, not_connected. For todos: urgent, high, pending, completed, overdue, unassigned'),
        ];
    }
}
