<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\StoreProjectRequest;
use App\Http\Requests\Api\UpdateProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Http\Resources\TagResource;
use App\Http\Resources\UserResource;
use App\Jobs\SyncWpAccountsJob;
use App\Models\Project;
use App\Models\Tag;
use App\Models\Todo;
use App\Models\User;
use App\Notifications\ProjectAssignedNotification;
use App\Notifications\ProjectStatusChangedNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;

/**
 * Project Controller
 * 
 * Full CRUD API for projects with filtering, pagination, and health checks.
 */
class ProjectController extends Controller
{
    /**
     * Display a paginated listing of projects.
     *
     * @param Request $request
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function index(Request $request)
    {
        Gate::authorize('viewAny', Project::class);

        $user = $request->user();
        
        $query = Project::query()
            ->select('projects.*')
            ->addSelect(['highest_todo_priority_rank' => Project::highestTodoPriorityRankSubquery()])
            ->with(['manager:id,name,email', 'managers:id,name,email', 'developer:id,name,email', 'developers:id,name,email', 'tags'])
            ->withCount(['todos', 'credentials', 'resources', 'maintenanceReports'])
            ->withCount(['todos as pending_todos_count' => fn($q) => $q->where('status', '!=', 'completed')]);

        // Role-based filtering (admins and is_admin users see all)
        if (!$user->isAdmin()) {
            if ($user->role === 'developer') {
                $query->where(function($q) use ($user) {
                    $q->where('developer_id', $user->id)
                      ->orWhereHas('developers', fn($sub) => $sub->where('users.id', $user->id));
                });
            } elseif ($user->role === 'manager') {
                $query->where(function($q) use ($user) {
                    $q->where('manager_id', $user->id)
                      ->orWhereHas('managers', fn($sub) => $sub->where('users.id', $user->id));
                });
            }
        }

        // Filter by health status
        if ($request->filled('health') && $request->health !== 'all') {
            $query->where('health_status', $request->health);
        }

        // Filter by security status
        if ($request->filled('security') && $request->security !== 'all') {
            $query->where('security_status', $request->security);
        }

        // Filter by manager
        if ($request->filled('manager_id')) {
            $managerId = $request->manager_id;
            $query->where(function($q) use ($managerId) {
                $q->where('manager_id', $managerId)
                  ->orWhereHas('managers', fn($sub) => $sub->where('users.id', $managerId));
            });
        }

        // Filter by developer
        if ($request->filled('developer_id')) {
            $developerId = $request->developer_id;
            $query->where(function($q) use ($developerId) {
                $q->where('developer_id', $developerId)
                  ->orWhereHas('developers', fn($sub) => $sub->where('users.id', $developerId));
            });
        }

        // Filter by tag
        if ($request->filled('tag')) {
            $query->whereHas('tags', fn($q) => $q->where('slug', $request->tag));
        }

        // Search
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('url', 'like', "%{$search}%")
                  ->orWhere('client_email', 'like', "%{$search}%")
                  ->orWhere('project_external_id', 'like', "%{$search}%");
            });
        }

        $perPage = min($request->integer('per_page', 15), 100);

        // Dynamic sorting
        $allowedSorts = ['created_at', 'updated_at', 'name', 'pending_todos_count'];
        $sortBy = in_array($request->input('sort_by'), $allowedSorts)
            ? $request->input('sort_by')
            : 'updated_at';
        $sortDir = $request->input('sort_dir') === 'asc' ? 'asc' : 'desc';

        $projects = $query->orderBy($sortBy, $sortDir)->paginate($perPage);

        return ProjectResource::collection($projects);
    }

    /**
     * Quick search for header autocomplete (max 5 results).
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function quickSearch(Request $request): JsonResponse
    {
        $user = $request->user();
        $search = $request->input('q', '');

        if (strlen($search) < 2) {
            return response()->json(['data' => []]);
        }

        $query = Project::query()
            ->select('id', 'name', 'url', 'project_external_id', 'health_status')
            ->with(['tags:id,name,color']);

        // Role-based filtering (admins and is_admin users see all)
        if (!$user->isAdmin()) {
            if ($user->role === 'developer') {
                $query->where(function($q) use ($user) {
                    $q->where('developer_id', $user->id)
                      ->orWhereHas('developers', fn($sub) => $sub->where('users.id', $user->id));
                });
            } elseif ($user->role === 'manager') {
                $query->where(function($q) use ($user) {
                    $q->where('manager_id', $user->id)
                      ->orWhereHas('managers', fn($sub) => $sub->where('users.id', $user->id));
                });
            }
        }

        // Search by name, URL (domain), external_id, or maint_id
        $query->where(function($q) use ($search) {
            $q->where('name', 'like', "%{$search}%")
              ->orWhere('url', 'like', "%{$search}%")
              ->orWhere('project_external_id', 'like', "%{$search}%")
              ->orWhere('id', '=', is_numeric($search) ? (int)$search : 0);
        });

        $projects = $query->limit(5)->get();

        return response()->json([
            'data' => $projects->map(fn($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'url' => $p->url,
                'external_id' => $p->project_external_id,
                'health_status' => $p->health_status,
                'tags' => $p->tags,
            ]),
        ]);
    }

    /**
     * Display a single project with all relationships.
     *
     * @param Project $project
     * @return ProjectResource
     */
    public function show(Project $project): ProjectResource
    {
        Gate::authorize('view', $project);

        $project->load([
            'credentials',
            'resources',
            'libraryResources',
            'todos.assignee:id,name,email',
            'todos.attachments',
            'todos.libraryResources',
            'manager:id,name,email',
            'managers:id,name,email',
            'developer:id,name,email',
            'developers:id,name,email',
            'tags',
            'maintenanceReports.user:id,name',
        ]);

        return new ProjectResource($project);
    }

    /**
     * Store a newly created project.
     *
     * @param StoreProjectRequest $request
     * @return JsonResponse
     */
    public function store(StoreProjectRequest $request): JsonResponse
    {
        Gate::authorize('create', Project::class);

        $validated = $request->validated();

        // Extract relationship arrays before creating project
        $tagIds = $validated['tag_ids'] ?? [];
        $developerIds = $validated['developer_ids'] ?? [];
        $managerIds = $validated['manager_ids'] ?? [];
        $addMaintenanceTodos = $validated['add_maintenance_todos'] ?? false;
        unset($validated['tag_ids'], $validated['developer_ids'], $validated['manager_ids'], $validated['add_maintenance_todos']);

        // Set defaults
        $validated['health_status'] = $validated['health_status'] ?? 'online';
        $validated['security_status'] = $validated['security_status'] ?? 'secure';

        // If manager_ids provided, also set legacy manager_id to first one
        if (!empty($managerIds) && empty($validated['manager_id'])) {
            $validated['manager_id'] = $managerIds[0];
        }

        $project = Project::create($validated);

        // Sync tags
        if (!empty($tagIds)) {
            $project->tags()->sync($tagIds);
        }

        // Sync managers and notify
        if (!empty($managerIds)) {
            $project->managers()->sync($managerIds);
            foreach ($managerIds as $mgrId) {
                $manager = User::find($mgrId);
                $manager?->notify(new ProjectAssignedNotification($project, 'manager'));
            }
        } elseif ($project->manager_id) {
            // Legacy single manager_id — also add to pivot
            $project->managers()->sync([$project->manager_id]);
            $manager = User::find($project->manager_id);
            $manager?->notify(new ProjectAssignedNotification($project, 'manager'));
        }

        // Sync developers and notify
        if (!empty($developerIds)) {
            $project->developers()->sync($developerIds);
            foreach ($developerIds as $devId) {
                $developer = User::find($devId);
                $developer?->notify(new ProjectAssignedNotification($project, 'developer'));
            }
        }

        // Create maintenance init todos if requested
        if ($addMaintenanceTodos) {
            $this->createMaintenanceTodos($project);
        }

        $project->load(['manager', 'managers', 'developers', 'tags']);

        // Dispatch WP account sync if team members were assigned
        $allTeamIds = array_merge($developerIds, $managerIds);
        if (!empty($allTeamIds) && $project->health_check_secret) {
            SyncWpAccountsJob::dispatch($project, $allTeamIds, [], false);
        }

        return $this->createdResponse(
            new ProjectResource($project),
            'Project created successfully'
        );
    }

    /**
     * Update the specified project.
     *
     * @param UpdateProjectRequest $request
     * @param Project $project
     * @return ProjectResource
     */
    public function update(UpdateProjectRequest $request, Project $project): ProjectResource
    {
        Gate::authorize('update', $project);

        $user = $request->user();
        $validated = $request->validated();

        // Track changes for notifications
        $oldManagerIds = $project->managers->pluck('id')->toArray();
        $oldDeveloperIds = $project->developers->pluck('id')->toArray();
        $oldHealthStatus = $project->health_status;
        $oldSecurityStatus = $project->security_status;
        $oldHealthCheckSecret = $project->health_check_secret;

        // Extract relationship arrays
        $tagIds = $validated['tag_ids'] ?? null;
        $developerIds = $validated['developer_ids'] ?? null;
        $managerIds = $validated['manager_ids'] ?? null;
        unset($validated['tag_ids'], $validated['developer_ids'], $validated['manager_ids']);

        // Role-based restrictions for assignments
        if (!$user->isAdmin()) {
            unset($validated['manager_id']);
            $managerIds = null;
        }
        if (!$user->isAdmin() && !$user->isManager()) {
            // Developers can't change developer assignments
            $developerIds = null;
        }

        $project->update($validated);

        // Sync tags if provided
        if ($tagIds !== null) {
            $project->tags()->sync($tagIds);
        }

        // Sync managers if provided and notify new ones
        if ($managerIds !== null) {
            $project->managers()->sync($managerIds);
            // Also keep legacy manager_id in sync
            $project->update(['manager_id' => !empty($managerIds) ? $managerIds[0] : null]);
            $newManagerIds = array_diff($managerIds, $oldManagerIds);
            foreach ($newManagerIds as $mgrId) {
                $manager = User::find($mgrId);
                $manager?->notify(new ProjectAssignedNotification($project, 'manager'));
            }
        } elseif (isset($validated['manager_id']) && $validated['manager_id'] != ($oldManagerIds[0] ?? null)) {
            // Legacy single manager_id update — also sync pivot
            if ($validated['manager_id']) {
                $project->managers()->sync([$validated['manager_id']]);
                $newManager = User::find($validated['manager_id']);
                $newManager?->notify(new ProjectAssignedNotification($project, 'manager'));
            } else {
                $project->managers()->detach();
            }
        }

        // Sync developers if provided and notify new ones
        if ($developerIds !== null) {
            $project->developers()->sync($developerIds);
            $newDeveloperIds = array_diff($developerIds, $oldDeveloperIds);
            foreach ($newDeveloperIds as $devId) {
                $developer = User::find($devId);
                $developer?->notify(new ProjectAssignedNotification($project, 'developer'));
            }
        }

        // Dispatch WP account sync if team members changed or plugin newly connected
        if ($project->health_check_secret) {
            // Force-reload relationships to get fresh data after sync() calls
            $project->load(['managers', 'developers']);

            $currentManagerIds = $project->managers->pluck('id')->toArray();
            $currentDeveloperIds = $project->developers->pluck('id')->toArray();
            $currentTeamIds = array_unique(array_merge($currentManagerIds, $currentDeveloperIds));
            $oldTeamIds = array_unique(array_merge($oldManagerIds, $oldDeveloperIds));

            $pluginNewlyConnected = empty($oldHealthCheckSecret) && !empty($project->health_check_secret);

            if ($pluginNewlyConnected && !empty($currentTeamIds)) {
                // Full sync to provision all existing team members on newly connected plugin
                SyncWpAccountsJob::dispatch($project, [], [], true);
            } else {
                $addedUserIds = array_values(array_diff($currentTeamIds, $oldTeamIds));
                $removedUserIds = array_values(array_diff($oldTeamIds, $currentTeamIds));

                if (!empty($addedUserIds) || !empty($removedUserIds)) {
                    SyncWpAccountsJob::dispatch($project, $addedUserIds, $removedUserIds);
                }
            }
        }

        // Notify team of status changes
        $this->notifyStatusChanges($project, $oldHealthStatus, $oldSecurityStatus);

        $project->load(['manager', 'managers', 'developers', 'tags']);

        return new ProjectResource($project);
    }

    /**
     * Remove the specified project.
     *
     * @param Project $project
     * @return JsonResponse
     */
    public function destroy(Project $project): JsonResponse
    {
        Gate::authorize('delete', $project);

        $project->delete();

        return $this->successResponse(null, 'Project deleted successfully');
    }
    /**
     * Manually trigger a health check for a project.
     *
     * @param Project $project
     * @return JsonResponse
     */
    public function checkHealth(Project $project): JsonResponse
    {
        Gate::authorize('update', $project);

        if (empty($project->url)) {
            return $this->errorResponse('Project URL not configured', 400);
        }

        try {
            $baseUrl = rtrim($project->url, '/');
            $hasPlugin = !empty($project->health_check_secret);

            // Two-tier: plugin health endpoint if connected, otherwise simple URL check.
            // Authenticate via header so the secret never appears in the request URL.
            $request = Http::timeout(15);
            if ($hasPlugin) {
                $checkUrl = "{$baseUrl}/wp-json/lsm/v1/health";
                $request = $request->withHeaders(['X-LSM-Key' => $project->health_check_secret]);
            } else {
                $checkUrl = $baseUrl;
            }

            $startTime = microtime(true);
            $response = $request->get($checkUrl);
            $responseTime = round((microtime(true) - $startTime) * 1000);
            
            if ($response->successful()) {
                $updateData = [
                    'last_health_check_at' => now(),
                    'response_time_ms' => $responseTime,
                ];

                $healthData = null;

                if ($hasPlugin) {
                    // Plugin connected — parse rich health data
                    $healthData = $response->json();
                    $sslInfo = $this->checkSSL($project->url);

                    $updateData['last_health_details'] = $healthData;
                    $updateData['wp_version'] = $healthData['wordpress']['version'] ?? null;
                    $updateData['php_version'] = $healthData['php']['version'] ?? null;
                    $updateData['outdated_plugins_count'] = $healthData['plugins']['outdated_count'] ?? 0;
                    $updateData['ssl_status'] = $sslInfo['status'];
                    $updateData['ssl_expires_at'] = $sslInfo['expires_at'] ?? null;
                    $updateData['health_status'] = $this->determineHealthStatus($healthData);
                } else {
                    // Simple URL check — store basic info
                    $updateData['last_health_details'] = [
                        'simple_check' => true,
                        'http_status' => $response->status(),
                        'checked_at' => now()->toIso8601String(),
                    ];
                    $updateData['health_status'] = 'online';
                }

                $project->update($updateData);

                // Log uptime check for historical tracking
                \App\Models\UptimeCheck::create([
                    'project_id' => $project->id,
                    'status' => 'up',
                    'http_status' => $response->status(),
                    'response_time_ms' => $responseTime,
                    'checked_at' => now(),
                ]);

                // After successful health check, sync WP accounts if plugin connected and team members exist
                if ($hasPlugin) {
                    $hasTeam = $project->developers()->exists() || $project->managers()->exists();
                    if ($hasTeam) {
                        SyncWpAccountsJob::dispatch($project, [], [], true);
                    }
                }

                return $this->successResponse([
                    'health_data' => $healthData,
                    'response_time_ms' => $responseTime,
                    'project' => new ProjectResource($project->fresh()),
                ], $hasPlugin ? 'Health check completed successfully' : 'Site is online');
            } else {
                $errorMessage = 'HTTP ' . $response->status();
                
                $project->update([
                    'last_health_check_at' => now(),
                    'response_time_ms' => $responseTime,
                    'health_status' => 'down_error',
                    'last_health_details' => [
                        'error' => true,
                        'error_type' => 'http_error',
                        'error_code' => $response->status(),
                        'error_message' => $errorMessage,
                        'checked_at' => now()->toIso8601String(),
                    ],
                ]);

                // Log failed uptime check
                \App\Models\UptimeCheck::create([
                    'project_id' => $project->id,
                    'status' => 'down',
                    'http_status' => $response->status(),
                    'response_time_ms' => $responseTime,
                    'error_message' => $errorMessage,
                    'checked_at' => now(),
                ]);

                return $this->errorResponse('Site returned error: ' . $errorMessage, 400);
            }
        } catch (\Exception $e) {
            $project->update([
                'last_health_check_at' => now(),
                'health_status' => 'down_error',
                'last_health_details' => [
                    'error' => true,
                    'error_type' => 'connection_error',
                    'error_message' => $e->getMessage(),
                    'checked_at' => now()->toIso8601String(),
                ],
            ]);

            // Log error uptime check
            \App\Models\UptimeCheck::create([
                'project_id' => $project->id,
                'status' => 'error',
                'error_message' => $e->getMessage(),
                'checked_at' => now(),
            ]);

            return $this->errorResponse('Failed to connect: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get uptime statistics for a project.
     *
     * @param Project $project
     * @param Request $request
     * @return JsonResponse
     */
    public function uptimeStats(Project $project, Request $request): JsonResponse
    {
        Gate::authorize('view', $project);

        $days = (int) $request->query('days', 30);
        $days = min(max($days, 1), 90); // Clamp between 1-90 days

        $stats = \App\Models\UptimeCheck::getStats($project->id, $days);

        // Get recent checks for chart data (last 50 checks or less)
        $recentChecks = \App\Models\UptimeCheck::forProject($project->id)
            ->lastDays($days)
            ->orderBy('checked_at', 'asc')
            ->limit(50)
            ->get(['status', 'response_time_ms', 'error_message', 'checked_at']);

        return $this->successResponse([
            'uptime_percentage' => $stats['uptime_percentage'],
            'total_checks' => $stats['total_checks'],
            'up_count' => $stats['up_count'],
            'down_count' => $stats['down_count'],
            'avg_response_time' => $stats['avg_response_time'] !== null
                ? round($stats['avg_response_time'])
                : null,
            'days' => $days,
            'recent_checks' => $recentChecks->map(fn ($check) => [
                'status' => $check->status,
                'response_time' => $check->response_time_ms,
                'error_message' => $check->error_message,
                'checked_at' => $check->checked_at->toIso8601String(),
            ]),
        ]);
    }

    /**
     * Get filter options (managers, developers, tags) for the projects list.
     *
     * @return JsonResponse
     */
    public function filterOptions(): JsonResponse
    {
        return $this->successResponse([
            'managers' => UserResource::collection(
                User::where('role', 'manager')->select('id', 'name', 'email')->orderBy('name')->get()
            ),
            'developers' => UserResource::collection(
                User::where('role', 'developer')->select('id', 'name', 'email')->orderBy('name')->get()
            ),
            'tags' => TagResource::collection(
                Tag::orderBy('name')->get()
            ),
            'health_statuses' => ['online', 'down_error', 'updating'],
            'security_statuses' => ['secure', 'monitoring', 'compromised', 'hacked'],
        ]);
    }

    /**
     * Get project statistics (overall, not filtered).
     *
     * @return JsonResponse
     */
    public function stats(): JsonResponse
    {
        return $this->successResponse([
            'total' => Project::count(),
            'online' => Project::where('health_status', 'online')->count(),
            'secure' => Project::where('security_status', 'secure')->count(),
            'issues' => Project::where(function($q) {
                $q->whereIn('health_status', ['offline', 'down_error'])
                  ->orWhereIn('security_status', ['hacked', 'compromised']);
            })->count(),
        ]);
    }

    /**
     * Create standard maintenance todos for a new project.
     */
    private function createMaintenanceTodos(Project $project): void
    {
        $todos = [
            ['title' => 'Check if Wordfence is installed', 'priority' => 'critical'],
            ['title' => 'Check if our new theme is installed', 'priority' => 'high'],
            ['title' => 'Check if new plugin for forms is installed', 'priority' => 'high'],
            ['title' => 'Check if database is clean', 'priority' => 'critical'],
            ['title' => 'Check if malicious files on server/file system', 'priority' => 'critical'],
        ];

        foreach ($todos as $todo) {
            Todo::create([
                'project_id' => $project->id,
                'title' => $todo['title'],
                'priority' => $todo['priority'],
                'status' => 'pending',
            ]);
        }
    }

    /**
     * Check SSL certificate status by connecting to the server.
     */
    private function checkSSL(string $url): array
    {
        $parsedUrl = parse_url($url);
        
        if (($parsedUrl['scheme'] ?? '') !== 'https') {
            return ['status' => 'none', 'message' => 'Not using HTTPS'];
        }
        
        $host = $parsedUrl['host'] ?? '';
        if (empty($host)) {
            return ['status' => 'none', 'message' => 'Invalid URL'];
        }

        try {
            $context = stream_context_create([
                'ssl' => [
                    'capture_peer_cert' => true,
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                ],
            ]);
            
            $socket = @stream_socket_client(
                "ssl://{$host}:443",
                $errno,
                $errstr,
                10,
                STREAM_CLIENT_CONNECT,
                $context
            );
            
            if (!$socket) {
                return ['status' => 'none', 'message' => "SSL connection failed: {$errstr}"];
            }
            
            $params = stream_context_get_params($socket);
            $cert = openssl_x509_parse($params['options']['ssl']['peer_certificate'] ?? null);
            fclose($socket);
            
            if (!$cert) {
                return ['status' => 'none', 'message' => 'Could not parse certificate'];
            }
            
            $expiresAt = isset($cert['validTo_time_t']) 
                ? date('Y-m-d', $cert['validTo_time_t']) 
                : null;
            $daysUntilExpiry = $expiresAt 
                ? (strtotime($expiresAt) - time()) / 86400 
                : null;
            
            if ($daysUntilExpiry !== null && $daysUntilExpiry < 0) {
                $status = 'expired';
            } elseif ($daysUntilExpiry !== null && $daysUntilExpiry < 30) {
                $status = 'expiring_soon';
            } else {
                $status = 'valid';
            }
            
            return [
                'status' => $status,
                'expires_at' => $expiresAt,
                'days_until_expiry' => $daysUntilExpiry ? (int) $daysUntilExpiry : null,
                'issuer' => $cert['issuer']['O'] ?? $cert['issuer']['CN'] ?? 'Unknown',
            ];
            
        } catch (\Exception $e) {
            return ['status' => 'none', 'message' => $e->getMessage()];
        }
    }

    /**
     * Determine health status based on health check data.
     */
    private function determineHealthStatus(array $healthData): string
    {
        if (!($healthData['ssl']['enabled'] ?? true)) {
            return 'down_error';
        }

        if (($healthData['updates']['core_update_available'] ?? false) || 
            ($healthData['plugins']['outdated_count'] ?? 0) > 5 ||
            ($healthData['security']['debug_mode'] ?? false)) {
            return 'updating';
        }

        return 'online';
    }

    /**
     * Notify team members of status changes.
     */
    private function notifyStatusChanges(Project $project, string $oldHealthStatus, string $oldSecurityStatus): void
    {
        if ($project->health_status === $oldHealthStatus && $project->security_status === $oldSecurityStatus) {
            return;
        }

        $teamMembers = collect();
        
        foreach ($project->managers as $manager) {
            $teamMembers->push($manager);
        }
        // Fallback: legacy manager_id
        if ($teamMembers->isEmpty() && $project->manager_id) {
            $teamMembers->push(User::find($project->manager_id));
        }
        if ($project->developer_id) {
            $teamMembers->push(User::find($project->developer_id));
        }
        foreach ($project->developers as $developer) {
            $teamMembers->push($developer);
        }
        
        $teamMembers = $teamMembers->filter()->unique('id');

        if ($project->health_status !== $oldHealthStatus) {
            foreach ($teamMembers as $member) {
                $member->notify(new ProjectStatusChangedNotification(
                    $project, 'health', $oldHealthStatus, $project->health_status
                ));
            }
        }

        if ($project->security_status !== $oldSecurityStatus) {
            foreach ($teamMembers as $member) {
                $member->notify(new ProjectStatusChangedNotification(
                    $project, 'security', $oldSecurityStatus, $project->security_status
                ));
            }
        }
    }
}
