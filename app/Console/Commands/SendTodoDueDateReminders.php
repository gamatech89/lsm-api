<?php

namespace App\Console\Commands;

use App\Models\Todo;
use App\Notifications\TodoDueDateReminderNotification;
use Illuminate\Console\Command;
use Carbon\Carbon;

class SendTodoDueDateReminders extends Command
{
    protected $signature = 'todos:send-due-reminders';
    protected $description = 'Send reminder notifications for todos due within the next 24 hours';

    public function handle(): int
    {
        $now = Carbon::now();
        $tomorrow = $now->copy()->addHours(24);

        // Find todos that are:
        // - Due within the next 24 hours
        // - Not completed or cancelled
        // - Have an assigned user
        $todos = Todo::where('status', '!=', 'completed')
            ->where('status', '!=', 'cancelled')
            ->whereNotNull('due_date')
            ->whereNotNull('assignee_id')
            ->whereBetween('due_date', [$now, $tomorrow])
            ->with(['assignee', 'project'])
            ->get();

        $sentCount = 0;

        foreach ($todos as $todo) {
            if (!$todo->assignee) {
                continue;
            }

            // Check if we already sent a reminder for this todo today
            $alreadySent = $todo->assignee->notifications()
                ->where('type', TodoDueDateReminderNotification::class)
                ->where('created_at', '>=', $now->copy()->startOfDay())
                ->whereJsonContains('data->todo_id', $todo->id)
                ->exists();

            if ($alreadySent) {
                continue;
            }

            $todo->assignee->notify(new TodoDueDateReminderNotification($todo));
            $sentCount++;
        }

        $this->info("Sent {$sentCount} due date reminders (checked {$todos->count()} todos).");

        return Command::SUCCESS;
    }
}
