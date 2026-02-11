<?php

namespace App\Mcp\Prompts;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;

class WeeklyStatusPrompt extends Prompt
{
    protected string $name = 'weekly-status';

    protected string $description = <<<'MARKDOWN'
        Generate a weekly status report summarizing time tracked, todos 
        completed, and site health changes across the week.
    MARKDOWN;

    public function handle(Request $request): Response
    {
        $content = <<<'PROMPT'
Please generate a comprehensive weekly status report for my LSM (Landeseiten Maintenance) work. Use the available tools and resources to gather data.

## Report Sections:

### 1. Time Summary
- Total hours tracked this week
- Breakdown by project
- Comparison to previous week if available
- Any timesheets pending submission or approval

### 2. Tasks Completed
- List of todos marked as completed this week
- Group by project
- Note any high-priority items completed

### 3. Tasks Created
- New todos added this week
- Current backlog size

### 4. Site Health Changes
- Any sites that went offline or had security issues
- Sites that were recovered or fixed
- Overall health trend

### 5. Key Accomplishments
- Summarize major work completed
- Highlight any significant fixes or updates

### 6. Next Week Priorities
- Based on current pending todos and site status
- Recommend focus areas

## Format
Present as a professional weekly report with:
- Clear section headers
- Metrics with numbers where possible
- Brief narrative summaries
- Executive summary at the top

Start by gathering time data for this week, then check project and todo status.
PROMPT;

        return Response::text($content);
    }

    public function arguments(): array
    {
        return [];
    }
}
