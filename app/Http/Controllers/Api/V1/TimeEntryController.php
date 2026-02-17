<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\TimeEntry;
use App\Models\Timesheet;
use App\Http\Resources\TimeEntryResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class TimeEntryController extends Controller
{
    /**
     * List time entries for current user (or all users for admins/managers)
     */
    public function index(Request $request)
    {
        $request->validate([
            'project_id' => 'nullable|exists:projects,id',
            'status' => 'nullable|in:draft,submitted,approved,rejected,paid',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'week' => 'nullable|integer|min:1|max:53',
            'year' => 'nullable|integer|min:2020',
            'per_page' => 'nullable|integer|min:10|max:1000',
            'all_users' => 'nullable|in:1,0,true,false',
            'user_id' => 'nullable|exists:users,id',
        ]);

        $user = Auth::user();
        $query = TimeEntry::with(['project', 'user', 'todo'])
            ->completed()
            ->orderByDesc('started_at');

        // Check if admin/manager wants all users' entries
        // Also auto-show all users when filtering by todo_id (admins/managers need to see dev time on todos)
        $showAllUsers = in_array($user->role, ['admin', 'manager']) && ($request->boolean('all_users') || $request->todo_id);
        
        if ($showAllUsers) {
            // Admins and managers can see all entries
            if ($request->user_id) {
                $query->forUser($request->user_id);
            }
            // Otherwise show all users
        } else {
            // Regular users only see their own entries
            $query->forUser(Auth::id());
        }

        // Filter by project
        if ($request->project_id) {
            $query->forProject($request->project_id);
        }

        // Filter by todo
        if ($request->todo_id) {
            $query->where('todo_id', $request->todo_id);
        }

        // Filter by status
        if ($request->status) {
            $query->byStatus($request->status);
        }

        // Filter by date range
        if ($request->date_from && $request->date_to) {
            $query->inDateRange($request->date_from, $request->date_to);
        }

        // Filter by week
        if ($request->week && $request->year) {
            $weekStart = Carbon::now()->setISODate($request->year, $request->week)->startOfWeek();
            $weekEnd = $weekStart->copy()->endOfWeek();
            $query->inDateRange($weekStart, $weekEnd);
        }

        $perPage = $request->per_page ?? 25;
        $entries = $query->paginate($perPage);

        return TimeEntryResource::collection($entries);
    }

    /**
     * Create a manual time entry (backfill)
     */
    public function store(Request $request)
    {
        $request->validate([
            'project_id' => 'required|exists:projects,id',
            'description' => 'nullable|string|max:500',
            'started_at' => 'required|date',
            'ended_at' => 'required|date|after:started_at',
            'is_billable' => 'nullable|boolean',
            'todo_id' => 'nullable|exists:todos,id',
        ]);

        $startedAt = Carbon::parse($request->started_at);
        $endedAt = Carbon::parse($request->ended_at);

        // Get or create timesheet for the entry's week
        $timesheet = Timesheet::getOrCreateForWeek(Auth::id(), $startedAt);

        // Check if timesheet is still editable
        // Note: Timesheet status check intentionally relaxed to support flexible invoicing.
        // Entries can be added regardless of timesheet status (open/submitted/approved).

        $entry = TimeEntry::create([
            'user_id' => Auth::id(),
            'project_id' => $request->project_id,
            'description' => $request->description,
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
            'duration_minutes' => $startedAt->diffInMinutes($endedAt),
            'is_billable' => $request->is_billable ?? true,
            'status' => TimeEntry::STATUS_DRAFT,
            'timesheet_id' => $timesheet->id,
            'todo_id' => $request->todo_id,
        ]);

        $entry->load('project');

        return $this->created(new TimeEntryResource($entry), 'Time entry created');
    }

    /**
     * Get a single time entry
     */
    public function show(TimeEntry $timeEntry)
    {
        // Authorization: users can only view their own entries (unless PM/admin)
        $user = Auth::user();
        
        if ($timeEntry->user_id !== $user->id && !in_array($user->role, ['admin', 'manager'])) {
            return $this->forbidden();
        }

        $timeEntry->load(['project', 'user', 'approver', 'todo']);

        return $this->success(new TimeEntryResource($timeEntry));
    }

    /**
     * Update a time entry
     */
    public function update(Request $request, TimeEntry $timeEntry)
    {
        // Only owner can update, and only if draft/rejected
        if ($timeEntry->user_id !== Auth::id()) {
            return $this->forbidden();
        }

        if (!in_array($timeEntry->status, [TimeEntry::STATUS_DRAFT, TimeEntry::STATUS_REJECTED])) {
            return $this->error('Cannot edit - entry has already been submitted.', 400);
        }

        $request->validate([
            'project_id' => 'nullable|exists:projects,id',
            'description' => 'nullable|string|max:500',
            'started_at' => 'nullable|date',
            'ended_at' => 'nullable|date|after:started_at',
            'is_billable' => 'nullable|boolean',
            'todo_id' => 'nullable|exists:todos,id',
        ]);

        $timeEntry->fill($request->only([
            'project_id',
            'description',
            'is_billable',
            'todo_id',
        ]));

        // Recalculate duration if times changed
        if ($request->started_at || $request->ended_at) {
            $startedAt = Carbon::parse($request->started_at ?? $timeEntry->started_at);
            $endedAt = Carbon::parse($request->ended_at ?? $timeEntry->ended_at);
            
            $timeEntry->started_at = $startedAt;
            $timeEntry->ended_at = $endedAt;
            $timeEntry->duration_minutes = $startedAt->diffInMinutes($endedAt);
        }

        // If rejected, reset to draft on edit
        if ($timeEntry->status === TimeEntry::STATUS_REJECTED) {
            $timeEntry->status = TimeEntry::STATUS_DRAFT;
            $timeEntry->rejection_reason = null;
        }

        $timeEntry->save();
        $timeEntry->load('project');

        return $this->success(new TimeEntryResource($timeEntry), 'Time entry updated');
    }

    /**
     * Delete a time entry
     */
    public function destroy(TimeEntry $timeEntry)
    {
        // Only owner can delete, and only if draft
        if ($timeEntry->user_id !== Auth::id()) {
            return $this->forbidden();
        }

        if ($timeEntry->status !== TimeEntry::STATUS_DRAFT) {
            return $this->error('Cannot delete - entry has already been submitted.', 400);
        }

        $timeEntry->delete();

        return $this->success(null, 'Time entry deleted');
    }

    /**
     * Get today's entries for current user
     */
    public function today()
    {
        $entries = TimeEntry::with(['project', 'todo'])
            ->forUser(Auth::id())
            ->whereDate('started_at', Carbon::today())
            ->orderByDesc('started_at')
            ->get();

        $totalMinutes = $entries->where('duration_minutes', '>', 0)->sum('duration_minutes');

        return $this->success([
            'entries' => TimeEntryResource::collection($entries),
            'total_minutes' => $totalMinutes,
            'formatted_total' => sprintf('%02d:%02d', floor($totalMinutes / 60), $totalMinutes % 60),
        ]);
    }

    /**
     * Submit selected entries for approval
     */
    public function submitEntries(Request $request)
    {
        $request->validate([
            'entry_ids' => 'required|array|min:1',
            'entry_ids.*' => 'integer|exists:time_entries,id',
        ]);

        $entryIds = $request->entry_ids;
        
        // Get entries that belong to current user and are draft
        $entries = TimeEntry::whereIn('id', $entryIds)
            ->where('user_id', Auth::id())
            ->where('status', TimeEntry::STATUS_DRAFT)
            ->get();

        if ($entries->isEmpty()) {
            return $this->error('No valid draft entries found to submit.', 400);
        }

        // Update all entries to submitted status
        $submittedCount = 0;
        foreach ($entries as $entry) {
            $entry->update(['status' => TimeEntry::STATUS_SUBMITTED]);
            $submittedCount++;
        }

        $totalMinutes = $entries->sum('duration_minutes');
        $hours = floor($totalMinutes / 60);
        $mins = $totalMinutes % 60;

        return $this->success([
            'submitted_count' => $submittedCount,
            'total_hours' => sprintf('%d:%02d', $hours, $mins),
        ], "{$submittedCount} " . ($submittedCount === 1 ? 'entry' : 'entries') . " submitted for approval");
    }

    /**
     * Approve selected entries (manager/admin only)
     */
    public function approveEntries(Request $request)
    {
        $request->validate([
            'entry_ids' => 'required|array|min:1',
            'entry_ids.*' => 'integer|exists:time_entries,id',
            'rate_overrides' => 'nullable|array',
            'rate_overrides.*' => 'numeric|min:0',
        ]);

        $user = Auth::user();

        // Only managers and admins can approve
        if (!in_array($user->role, ['admin', 'manager'])) {
            return $this->forbidden();
        }

        $entryIds = $request->entry_ids;
        $rateOverrides = $request->rate_overrides ?? [];

        // Get submitted entries
        $entries = TimeEntry::whereIn('id', $entryIds)
            ->where('status', TimeEntry::STATUS_SUBMITTED)
            ->get();

        if ($entries->isEmpty()) {
            return $this->error('No submitted entries found to approve.', 400);
        }

        // Apply rate overrides if provided
        foreach ($rateOverrides as $entryId => $rate) {
            TimeEntry::where('id', $entryId)->update(['hourly_rate' => $rate]);
        }

        // Approve the entries
        TimeEntry::whereIn('id', $entries->pluck('id'))
            ->update([
                'status' => TimeEntry::STATUS_APPROVED,
                'approved_by' => $user->id,
                'approved_at' => now(),
            ]);

        // Reload approved entries
        $approvedEntries = TimeEntry::with(['project', 'user'])
            ->whereIn('id', $entries->pluck('id'))
            ->get();

        // Group by user and create invoice
        $groupedByUser = $approvedEntries->groupBy('user_id');
        $invoiceNumbers = [];

        foreach ($groupedByUser as $userId => $userEntries) {
            $totalMinutes = $userEntries->sum('duration_minutes');
            $totalAmount = 0;
            
            foreach ($userEntries as $entry) {
                $rate = $entry->hourly_rate ?? $entry->user->hourly_rate ?? 0;
                $totalAmount += ($entry->duration_minutes / 60) * $rate;
            }

            // Create invoice
            $invoice = \App\Models\Invoice::create([
                'user_id' => $userId,
                'invoice_number' => \App\Models\Invoice::generateInvoiceNumber(),
                'period_start' => $userEntries->min('started_at'),
                'period_end' => $userEntries->max('ended_at'),
                'total_hours' => round($totalMinutes / 60, 2),
                'total_amount' => round($totalAmount, 2),
                'status' => \App\Models\Invoice::STATUS_APPROVED,
                'approved_by' => $user->id,
                'approved_at' => now(),
            ]);

            // Link entries to invoice
            TimeEntry::whereIn('id', $userEntries->pluck('id'))
                ->update(['invoice_id' => $invoice->id]);

            $invoiceNumbers[] = $invoice->invoice_number;
        }

        $count = $approvedEntries->count();
        $invoiceList = implode(', ', $invoiceNumbers);

        return $this->success([
            'approved_count' => $count,
            'invoice_numbers' => $invoiceNumbers,
        ], "{$count} entries approved. Invoice(s) created: {$invoiceList}");
    }

    /**
     * Reject selected entries (manager/admin only)
     */
    public function rejectEntries(Request $request)
    {
        $request->validate([
            'entry_ids' => 'required|array|min:1',
            'entry_ids.*' => 'integer|exists:time_entries,id',
            'reason' => 'required|string|max:500',
        ]);

        $user = Auth::user();

        // Only managers and admins can reject
        if (!in_array($user->role, ['admin', 'manager'])) {
            return $this->forbidden();
        }

        $entryIds = $request->entry_ids;
        $reason = $request->reason;

        // Get submitted entries
        $entries = TimeEntry::whereIn('id', $entryIds)
            ->where('status', TimeEntry::STATUS_SUBMITTED)
            ->get();

        if ($entries->isEmpty()) {
            return $this->error('No submitted entries found to reject.', 400);
        }

        // Reject the entries
        TimeEntry::whereIn('id', $entries->pluck('id'))
            ->update([
                'status' => TimeEntry::STATUS_REJECTED,
                'rejection_reason' => $reason,
            ]);

        $count = $entries->count();

        return $this->success([
            'rejected_count' => $count,
        ], "{$count} entries rejected");
    }
}
