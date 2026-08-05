<?php

namespace App\Mcp\Prompts;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;
use App\Mcp\Concerns\HasRequiredScope;

class MorningBriefingPrompt extends Prompt
{
    use HasRequiredScope;

    protected function requiredScope(): string
    {
        return 'mcp:read';
    }

    protected string $name = 'morning-briefing';

    protected string $description = <<<'MARKDOWN'
        Get a comprehensive morning summary of your LSM dashboard status 
        including at-risk sites, urgent todos, time tracking, and approvals.
    MARKDOWN;

    public function handle(Request $request): Response
    {
        if ($denied = $this->assertScope()) {
            return $denied;
        }

        $content = <<<'PROMPT'
Please give me a comprehensive morning briefing for my LSM (Landeseiten Maintenance) work day. Use the available tools and resources to gather information and present a clear summary.

## What I need:

### 1. Site Health Overview
- Check for any WordPress sites that are offline, at risk, or have security issues
- Highlight any critical sites that need immediate attention
- Use the `lsm://sites/at-risk` resource or `list-projects` tool with status filters

### 2. My Tasks for Today
- Show my urgent and high-priority todos
- Group tasks by project if helpful
- Use the `lsm://todos/mine` resource

### 3. Time Tracking Status
- Check if I have an active timer running
- Show how much time I've logged today and this week
- Use the `lsm://time/today` resource

### 4. Pending Approvals (if I'm a manager)
- Check if there are any timesheets waiting for my approval

### 5. Unread Notifications
- Briefly mention any important notifications

## Format
Present this as a clear, scannable briefing with:
- Emoji indicators for status (🔴 critical, 🟠 warning, 🟢 good)
- Bullet points for quick reading
- Action items highlighted
- A brief summary at the end with recommended first action

Start by getting the dashboard overview, then dive into specifics as needed.
PROMPT;

        return Response::text($content);
    }

    public function arguments(): array
    {
        return [];
    }
}
