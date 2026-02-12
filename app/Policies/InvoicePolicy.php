<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;

class InvoicePolicy
{
    /**
     * Admins can do anything.
     */
    public function before(User $user, string $ability): ?bool
    {
        if ($user->isAdmin()) {
            return true;
        }
        return null;
    }

    /**
     * Determine whether the user can view any invoices.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the invoice.
     * Users can view their own, managers can view invoices for their team.
     */
    public function view(User $user, Invoice $invoice): bool
    {
        // Users can view their own invoices
        if ($invoice->user_id === $user->id) {
            return true;
        }

        // Managers can view invoices of team members
        if ($user->role === 'manager') {
            return $this->managesUserProjects($user, $invoice->user_id);
        }

        return false;
    }

    /**
     * Determine whether the user can create invoices.
     * Only managers and admins can create invoices.
     */
    public function create(User $user): bool
    {
        return $user->role === 'manager';
    }

    /**
     * Determine whether the user can update the invoice.
     * Only draft/pending invoices can be updated by managers.
     */
    public function update(User $user, Invoice $invoice): bool
    {
        if ($user->role !== 'manager') {
            return false;
        }

        // Can only update pending invoices
        return in_array($invoice->status, [
            Invoice::STATUS_DRAFT,
            Invoice::STATUS_PENDING,
        ]);
    }

    /**
     * Determine whether the user can delete the invoice.
     * Only draft invoices can be deleted.
     */
    public function delete(User $user, Invoice $invoice): bool
    {
        if ($user->role !== 'manager') {
            return false;
        }

        return $invoice->status === Invoice::STATUS_DRAFT;
    }

    /**
     * Determine whether the user can approve the invoice.
     */
    public function approve(User $user, Invoice $invoice): bool
    {
        if ($user->role === 'manager') {
            return $this->managesUserProjects($user, $invoice->user_id);
        }

        return false;
    }

    /**
     * Determine whether the user can decline the invoice.
     */
    public function decline(User $user, Invoice $invoice): bool
    {
        return $this->approve($user, $invoice);
    }

    /**
     * Determine whether the user can mark the invoice as paid.
     * Only admins can mark as paid (handled by before()).
     */
    public function markAsPaid(User $user, Invoice $invoice): bool
    {
        return false; // Only admins via before()
    }

    /**
     * Check if manager has projects where the given user has worked.
     */
    private function managesUserProjects(User $manager, int $userId): bool
    {
        // Get projects this manager manages
        $projectIds = \App\Models\Project::where(function($q) use ($manager) {
            $q->where('manager_id', $manager->id)
              ->orWhereHas('managers', fn($sub) => $sub->where('users.id', $manager->id));
        })->pluck('id');
        
        // Check if the user has time entries on those projects
        return \App\Models\TimeEntry::whereIn('project_id', $projectIds)
            ->where('user_id', $userId)
            ->exists();
    }
}
