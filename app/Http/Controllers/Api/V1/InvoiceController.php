<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Invoice;
use App\Models\Timesheet;
use App\Models\TimeEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class InvoiceController extends Controller
{
    /**
     * List invoices
     */
    public function index(Request $request)
    {
        $request->validate([
            'status' => 'nullable|in:draft,pending,approved,declined,paid',
            'user_id' => 'nullable|exists:users,id',
        ]);

        $user = Auth::user();
        $query = Invoice::with(['user', 'timesheet', 'approver'])
            ->orderByDesc('created_at');

        // Filter by role
        if ($user->role === 'developer') {
            $query->forUser($user->id);
        } elseif ($request->user_id && in_array($user->role, ['admin', 'manager'])) {
            $query->forUser($request->user_id);
        }

        // Filter by status
        if ($request->status) {
            $query->byStatus($request->status);
        }

        $invoices = $query->paginate(20);

        return $this->success([
            'data' => $invoices->items(),
            'meta' => [
                'current_page' => $invoices->currentPage(),
                'total' => $invoices->total(),
            ],
        ]);
    }

    /**
     * Create invoice from approved timesheet entries
     */
    public function createFromTimesheet(Request $request)
    {
        $request->validate([
            'timesheet_id' => 'required|exists:timesheets,id',
            'notes' => 'nullable|string|max:1000',
        ]);

        $user = Auth::user();
        
        // Only managers and admins can create invoices
        if (!in_array($user->role, ['admin', 'manager'])) {
            return $this->forbidden();
        }

        $timesheet = Timesheet::with(['entries' => function ($q) {
            $q->where('status', TimeEntry::STATUS_APPROVED)
              ->whereNull('invoice_id');
        }, 'user'])->findOrFail($request->timesheet_id);

        if ($timesheet->entries->isEmpty()) {
            return $this->error('No approved entries without an invoice.', 400);
        }

        // Calculate totals
        $totalMinutes = $timesheet->entries->sum('duration_minutes');
        $totalHours = $totalMinutes / 60;
        
        $totalAmount = $timesheet->entries->sum(function ($entry) use ($timesheet) {
            $rate = $entry->hourly_rate ?? $timesheet->user->hourly_rate ?? 0;
            return ($entry->duration_minutes / 60) * $rate;
        });

        DB::transaction(function () use ($timesheet, $totalHours, $totalAmount, $request, &$invoice) {
            // Create invoice
            $invoice = Invoice::create([
                'user_id' => $timesheet->user_id,
                'timesheet_id' => $timesheet->id,
                'invoice_number' => Invoice::generateInvoiceNumber(),
                'period_start' => $timesheet->week_start,
                'period_end' => $timesheet->week_end,
                'total_hours' => $totalHours,
                'total_amount' => $totalAmount,
                'status' => Invoice::STATUS_PENDING,
                'notes' => $request->notes,
            ]);

            // Link entries to invoice
            $timesheet->entries()->update(['invoice_id' => $invoice->id]);
        });

        $invoice->load(['user', 'timesheet', 'entries.project']);

        return $this->created($invoice, 'Invoice created successfully');
    }

    /**
     * Get single invoice
     */
    public function show(Invoice $invoice)
    {
        $user = Auth::user();

        // Check access
        if ($invoice->user_id !== $user->id && !in_array($user->role, ['admin', 'manager'])) {
            return $this->forbidden();
        }

        $invoice->load(['user', 'timesheet', 'entries.project', 'approver']);

        return $this->success($invoice);
    }

    /**
     * Approve invoice
     */
    public function approve(Invoice $invoice)
    {
        $user = Auth::user();

        if (!in_array($user->role, ['admin', 'manager'])) {
            return $this->forbidden();
        }

        if ($invoice->status !== Invoice::STATUS_PENDING) {
            return $this->error('Invoice is not pending approval.', 400);
        }

        $invoice->approve($user->id);
        $invoice->load(['user', 'approver']);

        return $this->success($invoice, 'Invoice approved');
    }

    /**
     * Decline invoice
     */
    public function decline(Invoice $invoice)
    {
        $user = Auth::user();

        if (!in_array($user->role, ['admin', 'manager'])) {
            return $this->forbidden();
        }

        if ($invoice->status !== Invoice::STATUS_PENDING) {
            return $this->error('Invoice is not pending.', 400);
        }

        $invoice->decline();

        return $this->success($invoice, 'Invoice declined');
    }

    /**
     * Mark invoice as paid
     */
    public function markAsPaid(Invoice $invoice)
    {
        $user = Auth::user();

        if (!in_array($user->role, ['admin'])) {
            return $this->forbidden();
        }

        if (!in_array($invoice->status, [Invoice::STATUS_APPROVED, Invoice::STATUS_PENDING])) {
            return $this->error('Invoice must be approved before marking as paid.', 400);
        }

        $invoice->markAsPaid();

        // Also update all entries to paid
        $invoice->entries()->update(['status' => TimeEntry::STATUS_PAID]);

        return $this->success($invoice, 'Invoice marked as paid');
    }

    /**
     * Get pending invoices (for finance)
     */
    public function pending()
    {
        $user = Auth::user();

        if (!in_array($user->role, ['admin', 'manager'])) {
            return $this->forbidden();
        }

        $invoices = Invoice::with(['user', 'timesheet'])
            ->pending()
            ->orderBy('created_at')
            ->get();

        return $this->success($invoices);
    }

    /**
     * Download invoice as PDF
     */
    public function downloadPdf(Request $request, Invoice $invoice)
    {
        $user = Auth::user();

        // Check access - owner or admin/manager
        if ($invoice->user_id !== $user->id && !in_array($user->role, ['admin', 'manager'])) {
            return $this->forbidden();
        }

        $invoice->load(['user', 'entries.project', 'entries.todo']);
        $invoiceUser = $invoice->user;

        // Allow custom invoice number and from name
        $customInvoiceNumber = $request->query('custom_invoice_number', $invoice->invoice_number);
        $fromName = $request->query('from_name', $invoiceUser->billing_company_name ?? $invoiceUser->name);

        // Group entries by project for line items
        $grouped = $invoice->entries->groupBy('project_id');
        $lineItems = [];

        foreach ($grouped as $projectId => $entries) {
            $project = $entries->first()->project;
            $totalMinutes = $entries->sum('duration_minutes');
            $hours = $totalMinutes / 60;

            // Get the rate: use entry-level rate, fallback to user rate
            $rate = $entries->first()->hourly_rate ?? $invoiceUser->hourly_rate ?? 0;
            $amount = $hours * $rate;

            // Collect unique task names
            $tasks = $entries
                ->filter(fn($e) => $e->todo)
                ->pluck('todo.title')
                ->unique()
                ->values()
                ->toArray();

            $lineItems[] = [
                'description' => $project ? ('Development – ' . $project->name) : 'Development',
                'project_url' => $project->url ?? null,
                'project_slug' => $project ? str_replace(['https://', 'http://'], '', $project->url ?? $project->name) : '',
                'hours' => round($hours, 1),
                'rate' => $rate,
                'amount' => round($amount, 2),
                'tasks' => $tasks,
            ];
        }

        $subtotal = collect($lineItems)->sum('amount');
        $taxRate = config('invoice.tax_rate', 0);
        $taxAmount = $subtotal * ($taxRate / 100);
        $total = $subtotal + $taxAmount;

        $data = [
            'invoice' => $invoice,
            'user' => $invoiceUser,
            'customInvoiceNumber' => $customInvoiceNumber,
            'fromName' => $fromName,
            'lineItems' => $lineItems,
            'subtotal' => $subtotal,
            'taxRate' => $taxRate,
            'taxLabel' => config('invoice.tax_label', 'Tax'),
            'taxAmount' => $taxAmount,
            'total' => $total,
            'currency' => config('invoice.currency_symbol', '$'),
            'billTo' => [
                'company_name' => config('invoice.company_name'),
                'company_address' => config('invoice.company_address'),
            ],
        ];

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.invoice_pdf', $data);
        $pdf->setPaper('A4', 'portrait');

        $filename = $customInvoiceNumber . '.pdf';

        return $pdf->download($filename);
    }
}
