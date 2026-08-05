<?php

namespace App\Mcp\Tools;

use App\Models\Invoice;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use App\Mcp\Concerns\HasRequiredScope;

class ListInvoicesTool extends Tool
{
    use HasRequiredScope;

    protected function requiredScope(): string
    {
        return 'mcp:read';
    }

    protected string $name = 'list-invoices';

    protected string $description = <<<'MARKDOWN'
        List invoices. Can filter by status (draft, sent, paid, overdue).
        Shows invoice number, amount, status, and client.
    MARKDOWN;

    public function handle(Request $request): Response
    {
        if ($denied = $this->assertScope()) {
            return $denied;
        }

        $user = Auth::user();
        $input = $request->all();

        // Only admins and managers can view invoices
        if (!in_array($user->role, ['admin', 'manager'])) {
            return Response::error('Only admins and managers can view invoices.');
        }

        $query = Invoice::with(['project', 'user']);

        if (!empty($input['status'])) {
            $query->where('status', $input['status']);
        }

        if (!empty($input['project_id'])) {
            $query->where('project_id', $input['project_id']);
        }

        $invoices = $query->orderBy('created_at', 'desc')
            ->limit(20)
            ->get();

        if ($invoices->isEmpty()) {
            return Response::text("No invoices found.");
        }

        $lines = ["**📄 Invoices ({$invoices->count()})**\n"];

        foreach ($invoices as $invoice) {
            $statusEmoji = match ($invoice->status) {
                'paid' => '✅',
                'sent' => '📤',
                'draft' => '📝',
                'overdue' => '⚠️',
                default => '📄',
            };

            $amount = number_format($invoice->total_amount ?? 0, 2);
            $project = $invoice->project->name ?? 'General';

            $lines[] = sprintf(
                "%s **#%s** | €%s | %s | %s",
                $statusEmoji,
                $invoice->invoice_number,
                $amount,
                ucfirst($invoice->status),
                $project
            );
        }

        return Response::text(implode("\n", $lines));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()
                ->description('Filter by status: draft, sent, paid, overdue'),
            'project_id' => $schema->integer()
                ->description('Filter by project ID'),
        ];
    }
}
