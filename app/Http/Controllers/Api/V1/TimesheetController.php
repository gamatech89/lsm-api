<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Timesheet;
use App\Http\Resources\TimesheetResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class TimesheetController extends Controller
{
    /**
     * List timesheets for current user
     */
    public function index(Request $request)
    {
        $request->validate([
            'status' => 'nullable|in:open,submitted,approved,rejected,paid',
            'year' => 'nullable|integer|min:2020',
        ]);

        $query = Timesheet::with(['user'])
            ->withCount('entries')
            ->forUser(Auth::id())
            ->orderByDesc('year')
            ->orderByDesc('week_number');

        if ($request->status) {
            $query->byStatus($request->status);
        }

        if ($request->year) {
            $query->where('year', $request->year);
        }

        $timesheets = $query->paginate(12);

        return TimesheetResource::collection($timesheets);
    }

    /**
     * Get current week's timesheet
     */
    public function current()
    {
        $timesheet = Timesheet::currentWeek(Auth::id());
        $timesheet->load(['entries.project']);
        $timesheet->recalculateTotals();

        return $this->success(new TimesheetResource($timesheet));
    }

    /**
     * Get a specific timesheet
     */
    public function show(Timesheet $timesheet)
    {
        $user = Auth::user();

        // Authorization
        if ($timesheet->user_id !== $user->id && !in_array($user->role, ['admin', 'manager'])) {
            return $this->forbidden();
        }

        $timesheet->load(['entries.project', 'user', 'approver']);

        return $this->success(new TimesheetResource($timesheet));
    }

    /**
     * Submit timesheet for approval
     */
    public function submit(Timesheet $timesheet)
    {
        // Only owner can submit
        if ($timesheet->user_id !== Auth::id()) {
            return $this->forbidden();
        }

        // Check if already submitted
        if (!in_array($timesheet->status, [Timesheet::STATUS_OPEN, Timesheet::STATUS_REJECTED])) {
            return $this->error('Timesheet has already been submitted.', 400);
        }

        // Check if there are entries
        if ($timesheet->entries()->count() === 0) {
            return $this->error('Cannot submit an empty timesheet.', 400);
        }

        // Check if any entries are still running
        if ($timesheet->entries()->running()->exists()) {
            return $this->error('Please stop all running timers before submitting.', 400);
        }

        $timesheet->submit();

        // TODO: Send notification to PM

        return $this->success(new TimesheetResource($timesheet), 'Timesheet submitted for approval');
    }

    /**
     * Get timesheets pending approval (for PMs)
     */
    public function pending(Request $request)
    {
        $user = Auth::user();

        // Only managers and admins can approve
        if (!in_array($user->role, ['admin', 'manager'])) {
            return $this->forbidden();
        }

        // Find users who have submitted entries
        $query = \App\Models\TimeEntry::with(['user', 'project'])
            ->where('status', \App\Models\TimeEntry::STATUS_SUBMITTED)
            ->select('user_id')
            ->distinct();

        // If manager, only show entries from projects they manage
        if ($user->role === 'manager') {
            $projectIds = \App\Models\Project::where('manager_id', $user->id)->pluck('id');
            $query->whereIn('project_id', $projectIds);
        }

        $userIds = $query->pluck('user_id');

        // Build virtual "pending approvals" grouped by user
        $pendingApprovals = [];
        
        foreach ($userIds as $userId) {
            $entriesQuery = \App\Models\TimeEntry::with(['project', 'user'])
                ->where('user_id', $userId)
                ->where('status', \App\Models\TimeEntry::STATUS_SUBMITTED);
            
            // Manager filtering
            if ($user->role === 'manager') {
                $projectIds = \App\Models\Project::where('manager_id', $user->id)->pluck('id');
                $entriesQuery->whereIn('project_id', $projectIds);
            }
            
            $entries = $entriesQuery->orderBy('started_at')->get();
            
            if ($entries->isEmpty()) continue;
            
            $totalMinutes = $entries->sum('duration_minutes');
            $hours = floor($totalMinutes / 60);
            $mins = $totalMinutes % 60;
            
            $firstEntry = $entries->first();
            $lastEntry = $entries->last();
            
            $pendingApprovals[] = [
                'id' => $userId, // Using user_id as the "timesheet" id for compatibility
                'user_id' => $userId,
                'user' => $firstEntry->user,
                'week_label' => 'Pending Entries',
                'week_number' => null,
                'year' => null,
                'status' => 'submitted',
                'total_minutes' => $totalMinutes,
                'formatted_total' => sprintf('%d:%02d', $hours, $mins),
                'entries_count' => $entries->count(),
                'entries' => \App\Http\Resources\TimeEntryResource::collection($entries),
                'submitted_at' => $entries->min('updated_at'),
            ];
        }

        return $this->success($pendingApprovals);
    }

    /**
     * Approve a timesheet (or specific entries) and create invoice
     */
    public function approve(Request $request, Timesheet $timesheet)
    {
        $request->validate([
            'entry_ids' => 'nullable|array',
            'entry_ids.*' => 'integer|exists:time_entries,id',
            'rate_overrides' => 'nullable|array',
            'rate_overrides.*' => 'numeric|min:0',
        ]);

        $user = Auth::user();

        // Only managers and admins can approve
        if (!in_array($user->role, ['admin', 'manager'])) {
            return $this->forbidden();
        }

        // Check if submitted
        if ($timesheet->status !== Timesheet::STATUS_SUBMITTED) {
            return $this->error('Timesheet is not pending approval.', 400);
        }

        $entryIds = $request->entry_ids;
        $rateOverrides = $request->rate_overrides ?? [];

        // Apply rate overrides
        if (!empty($rateOverrides)) {
            foreach ($rateOverrides as $entryId => $rate) {
                $timesheet->entries()
                    ->where('id', $entryId)
                    ->update(['hourly_rate' => $rate]);
            }
        }

        // Get the entries to approve
        $entriesToApprove = !empty($entryIds) 
            ? $timesheet->entries()->whereIn('id', $entryIds)->get()
            : $timesheet->entries()->get();

        if ($entriesToApprove->isEmpty()) {
            return $this->error('No entries to approve.', 400);
        }

        // Approve the entries
        $approvedEntryIds = $entriesToApprove->pluck('id')->toArray();
        $timesheet->entries()
            ->whereIn('id', $approvedEntryIds)
            ->update([
                'status' => \App\Models\TimeEntry::STATUS_APPROVED,
                'approved_by' => $user->id,
                'approved_at' => now(),
            ]);

        // Reload approved entries with updated data
        $approvedEntries = $timesheet->entries()
            ->whereIn('id', $approvedEntryIds)
            ->get();

        // Calculate totals for invoice
        $totalMinutes = $approvedEntries->sum('duration_minutes') ?? 0;
        $totalHours = $totalMinutes / 60;
        
        $totalAmount = $approvedEntries->sum(function ($entry) use ($timesheet) {
            $rate = $entry->hourly_rate ?? $timesheet->user->hourly_rate ?? 45;
            return (($entry->duration_minutes ?? 0) / 60) * $rate;
        });

        // Create invoice with APPROVED status
        $invoice = \App\Models\Invoice::create([
            'user_id' => $timesheet->user_id,
            'timesheet_id' => $timesheet->id,
            'invoice_number' => \App\Models\Invoice::generateInvoiceNumber(),
            'period_start' => $timesheet->week_start,
            'period_end' => $timesheet->week_end,
            'total_hours' => $totalHours,
            'total_amount' => $totalAmount,
            'status' => \App\Models\Invoice::STATUS_APPROVED,
            'approved_by' => $user->id,
            'approved_at' => now(),
        ]);

        // Link approved entries to invoice
        $timesheet->entries()
            ->whereIn('id', $approvedEntryIds)
            ->update(['invoice_id' => $invoice->id]);

        // Check if all entries are now approved
        $remainingDraft = $timesheet->entries()
            ->whereIn('status', [\App\Models\TimeEntry::STATUS_DRAFT, \App\Models\TimeEntry::STATUS_SUBMITTED])
            ->count();

        if ($remainingDraft === 0) {
            // All entries approved, approve the timesheet
            $timesheet->approve($user->id);
        }

        $timesheet->load(['entries.project', 'user']);
        $invoice->load(['user', 'entries.project']);

        return $this->success([
            'timesheet' => new TimesheetResource($timesheet),
            'invoice' => $invoice,
        ], 'Entries approved and invoice #' . $invoice->invoice_number . ' created');
    }

    /**
     * Reject a timesheet
     */
    public function reject(Request $request, Timesheet $timesheet)
    {
        $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $user = Auth::user();

        // Only managers and admins can reject
        if (!in_array($user->role, ['admin', 'manager'])) {
            return $this->forbidden();
        }

        // Check if submitted
        if ($timesheet->status !== Timesheet::STATUS_SUBMITTED) {
            return $this->error('Timesheet is not pending approval.', 400);
        }

        $timesheet->reject($user->id, $request->reason);

        // TODO: Send notification to user

        return $this->success(new TimesheetResource($timesheet), 'Timesheet rejected');
    }

    /**
     * Get timesheet by week
     */
    public function byWeek(Request $request)
    {
        $request->validate([
            'week' => 'required|integer|min:1|max:53',
            'year' => 'required|integer|min:2020',
        ]);

        $timesheet = Timesheet::with(['entries.project'])
            ->forUser(Auth::id())
            ->forWeek($request->week, $request->year)
            ->first();

        if (!$timesheet) {
            // Create if doesn't exist
            $date = Carbon::now()->setISODate($request->year, $request->week);
            $timesheet = Timesheet::getOrCreateForWeek(Auth::id(), $date);
            $timesheet->load(['entries.project']);
        }

        return $this->success(new TimesheetResource($timesheet));
    }
}
