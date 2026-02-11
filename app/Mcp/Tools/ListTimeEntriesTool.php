<?php

namespace App\Mcp\Tools;

use App\Models\TimeEntry;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ListTimeEntriesTool extends Tool
{
    protected string $name = 'list-time-entries';

    protected string $description = <<<'MARKDOWN'
        List time entries. Can filter by project, user, date range, or show today/this week.
        Shows time logged with project and description.
    MARKDOWN;

    public function handle(Request $request): Response
    {
        $user = Auth::user();
        $input = $request->all();

        $query = TimeEntry::with(['project', 'user', 'todo']);

        // Admins see all, others see their own
        if ($user->role !== 'admin') {
            $query->where('user_id', $user->id);
        } elseif (!empty($input['user_id'])) {
            $query->where('user_id', $input['user_id']);
        }

        if (!empty($input['project_id'])) {
            $query->where('project_id', $input['project_id']);
        }

        // Date filtering
        if (!empty($input['today']) && $input['today']) {
            $query->whereDate('date', Carbon::today());
        } elseif (!empty($input['this_week']) && $input['this_week']) {
            $query->whereBetween('date', [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()]);
        } elseif (!empty($input['date_from'])) {
            $query->whereDate('date', '>=', $input['date_from']);
            if (!empty($input['date_to'])) {
                $query->whereDate('date', '<=', $input['date_to']);
            }
        }

        $entries = $query->orderBy('date', 'desc')
            ->orderBy('start_time', 'desc')
            ->limit(25)
            ->get();

        if ($entries->isEmpty()) {
            return Response::text("No time entries found for the specified criteria.");
        }

        $totalMinutes = $entries->sum('duration_minutes');
        $hours = floor($totalMinutes / 60);
        $mins = $totalMinutes % 60;

        $lines = ["**⏱️ Time Entries ({$entries->count()})** — Total: {$hours}h {$mins}m\n"];

        foreach ($entries as $entry) {
            $duration = sprintf('%dh %dm', floor($entry->duration_minutes / 60), $entry->duration_minutes % 60);
            $project = $entry->project->name ?? 'Unknown';
            $desc = $entry->description ? " — {$entry->description}" : '';
            $todoRef = $entry->todo ? " [Todo: {$entry->todo->title}]" : '';

            $lines[] = sprintf(
                "• **%s** | %s | %s%s%s",
                Carbon::parse($entry->date)->format('M d'),
                $duration,
                $project,
                $desc,
                $todoRef
            );
        }

        return Response::text(implode("\n", $lines));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->integer()
                ->description('Filter by project ID'),
            'user_id' => $schema->integer()
                ->description('Filter by user ID (admin only)'),
            'today' => $schema->boolean()
                ->description('Show only today\'s entries'),
            'this_week' => $schema->boolean()
                ->description('Show entries from this week'),
            'date_from' => $schema->string()
                ->description('Start date filter (YYYY-MM-DD)'),
            'date_to' => $schema->string()
                ->description('End date filter (YYYY-MM-DD)'),
        ];
    }
}
