<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * File attached to a support ticket or one of its messages.
 * Files live on the local disk under support-attachments/{ticket_id}/.
 */
class SupportTicketAttachment extends Model
{
    protected $fillable = [
        'support_ticket_id',
        'support_ticket_message_id',
        'filename',
        'path',
        'mime',
        'size',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(SupportTicketMessage::class, 'support_ticket_message_id');
    }
}
