<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;

/**
 * Support Ticket Model
 * 
 * Represents a support request submitted by a client via WordPress plugin.
 */
class SupportTicket extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'project_id',
        'ticket_number',
        'type',
        'subject',
        'message',
        'client_email',
        'client_name',
        'problem_page',
        'site_url',
        'status',
        'priority',
        'todo_id',
        'read_at',
        'resolved_at',
        'resolution_notes',
    ];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * Type labels with icons.
     */
    public const TYPE_LABELS = [
        'bug' => '🐛 Bug / Error',
        'content' => '📝 Content Change',
        'design' => '🎨 Design Change',
        'feature' => '✨ New Feature',
        'question' => '❓ Question',
        'urgent' => '🚨 URGENT',
    ];

    /**
     * Status labels.
     */
    public const STATUS_LABELS = [
        'open' => 'Open',
        'in_progress' => 'In Progress',
        'resolved' => 'Resolved',
        'closed' => 'Closed',
    ];

    /**
     * Priority labels.
     */
    public const PRIORITY_LABELS = [
        'low' => 'Low',
        'medium' => 'Medium',
        'high' => 'High',
        'critical' => 'Critical',
    ];

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($ticket) {
            if (empty($ticket->ticket_number)) {
                $ticket->ticket_number = static::generateTicketNumber();
            }
        });
    }

    /**
     * Generate unique ticket number.
     */
    public static function generateTicketNumber(): string
    {
        $lastTicket = static::withTrashed()->orderByDesc('id')->first();
        $nextNumber = $lastTicket ? $lastTicket->id + 1 : 1;
        return 'ST-' . str_pad($nextNumber, 5, '0', STR_PAD_LEFT);
    }

    // =========================================================================
    // RELATIONSHIPS
    // =========================================================================

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function todo(): BelongsTo
    {
        return $this->belongsTo(Todo::class);
    }

    // =========================================================================
    // SCOPES
    // =========================================================================

    /**
     * Filter by unread tickets.
     */
    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    /**
     * Filter by open tickets (not resolved or closed).
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', ['open', 'in_progress']);
    }

    /**
     * Filter by project.
     */
    public function scopeByProject(Builder $query, int $projectId): Builder
    {
        return $query->where('project_id', $projectId);
    }

    // =========================================================================
    // ACCESSORS
    // =========================================================================

    /**
     * Check if ticket is read.
     */
    public function getIsReadAttribute(): bool
    {
        return $this->read_at !== null;
    }

    /**
     * Get type label with emoji.
     */
    public function getTypeLabelAttribute(): string
    {
        return self::TYPE_LABELS[$this->type] ?? $this->type;
    }

    /**
     * Check if ticket is open.
     */
    public function getIsOpenAttribute(): bool
    {
        return in_array($this->status, ['open', 'in_progress']);
    }

    // =========================================================================
    // METHODS
    // =========================================================================

    /**
     * Mark ticket as read.
     */
    public function markAsRead(): void
    {
        if (!$this->read_at) {
            $this->update(['read_at' => now()]);
        }
    }

    /**
     * Resolve the ticket.
     */
    public function resolve(?string $notes = null): void
    {
        $this->update([
            'status' => 'resolved',
            'resolved_at' => now(),
            'resolution_notes' => $notes,
        ]);
    }

    /**
     * Create a todo from this ticket.
     */
    public function createTodo(): Todo
    {
        $todo = Todo::create([
            'project_id' => $this->project_id,
            'title' => "[{$this->ticket_number}] {$this->subject}",
            'description' => "**From:** {$this->client_name} <{$this->client_email}>\n\n" .
                           "**Type:** {$this->type_label}\n\n" .
                           "**Problem Page:** {$this->problem_page}\n\n" .
                           "---\n\n{$this->message}",
            'priority' => $this->priority,
            'status' => 'pending',
        ]);

        $this->update(['todo_id' => $todo->id]);

        return $todo;
    }
}
