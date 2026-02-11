<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class ClaudeService
{
    protected ?string $apiKey;
    protected string $model;
    protected string $baseUrl = 'https://api.anthropic.com/v1';

    public function __construct()
    {
        $this->apiKey = config('services.anthropic.api_key');
        $this->model = config('services.anthropic.model', 'claude-sonnet-4-20250514');
    }

    /**
     * Send a message to Claude and handle tool use loop.
     */
    public function chat(string $message, array $context = []): array
    {
        $user = auth()->user();
        $language = $context['language'] ?? 'en';
        
        $systemPrompt = $this->buildSystemPrompt($user, $language);
        
        $messages = [
            ['role' => 'user', 'content' => $message],
        ];

        // Add conversation history if provided
        if (!empty($context['history'])) {
            $messages = array_merge($context['history'], $messages);
        }

        $toolsUsed = [];
        $maxIterations = 8; // Allow more iterations for complex tasks
        $iteration = 0;

        while ($iteration < $maxIterations) {
            $iteration++;

            $response = $this->callClaude($systemPrompt, $messages);

            if (!$response['success']) {
                return $response;
            }

            $content = $response['content'];
            $stopReason = $response['stop_reason'];

            // Check if Claude wants to use tools
            if ($stopReason === 'tool_use') {
                // Process tool calls
                $toolResults = [];
                $assistantContent = [];

                foreach ($content as $block) {
                    if ($block['type'] === 'tool_use') {
                        $toolName = $block['name'];
                        $toolInput = $block['input'] ?? [];
                        $toolId = $block['id'];

                        $toolsUsed[] = $toolName;

                        // Execute the tool
                        $result = $this->executeTool($toolName, $toolInput, $user);

                        $toolResults[] = [
                            'type' => 'tool_result',
                            'tool_use_id' => $toolId,
                            'content' => $result,
                        ];

                        // Ensure input is always an object (not empty array) for Claude API
                        $sanitizedBlock = $block;
                        if (empty($sanitizedBlock['input']) || (is_array($sanitizedBlock['input']) && count($sanitizedBlock['input']) === 0)) {
                            $sanitizedBlock['input'] = new \stdClass();
                        }
                        $assistantContent[] = $sanitizedBlock;
                    } elseif ($block['type'] === 'text') {
                        $assistantContent[] = $block;
                    }
                }

                // Add assistant message with tool use
                $messages[] = ['role' => 'assistant', 'content' => $assistantContent];
                
                // Add tool results
                $messages[] = ['role' => 'user', 'content' => $toolResults];

            } else {
                // No more tool calls, return the final response
                $textResponse = '';
                foreach ($content as $block) {
                    if ($block['type'] === 'text') {
                        $textResponse .= $block['text'];
                    }
                }

                return [
                    'success' => true,
                    'response' => $textResponse,
                    'tools_used' => array_unique($toolsUsed),
                ];
            }
        }

        return [
            'success' => false,
            'error' => 'Max tool iterations reached',
        ];
    }

    /**
     * Call Claude API with retry logic for rate limits.
     */
    protected function callClaude(string $systemPrompt, array $messages, int $retryCount = 0): array
    {
        $maxRetries = 3;
        
        try {
            $response = Http::timeout(90)
                ->withHeaders([
                    'x-api-key' => $this->apiKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type' => 'application/json',
                ])
                ->post($this->baseUrl . '/messages', [
                    'model' => $this->model,
                    'max_tokens' => 4096,
                    'system' => $systemPrompt,
                    'messages' => $messages,
                    'tools' => $this->getToolDefinitions(),
                ]);

            // Handle rate limit (429) with exponential backoff
            if ($response->status() === 429 && $retryCount < $maxRetries) {
                $waitTime = pow(2, $retryCount); // 1s, 2s, 4s
                Log::warning('Claude API rate limit hit, retrying...', [
                    'retry' => $retryCount + 1,
                    'wait_seconds' => $waitTime,
                ]);
                sleep($waitTime);
                return $this->callClaude($systemPrompt, $messages, $retryCount + 1);
            }

            if (!$response->successful()) {
                Log::error('Claude API error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                $errorMessage = 'Claude API error: ' . $response->status();
                if ($response->status() === 429) {
                    $errorMessage = 'Rate limit exceeded. Please wait a moment and try again.';
                }

                return [
                    'success' => false,
                    'error' => $errorMessage,
                ];
            }

            $data = $response->json();

            return [
                'success' => true,
                'content' => $data['content'] ?? [],
                'stop_reason' => $data['stop_reason'] ?? 'end_turn',
            ];

        } catch (\Exception $e) {
            Log::error('Claude API exception', ['error' => $e->getMessage()]);

            return [
                'success' => false,
                'error' => 'Failed to connect to Claude: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Build comprehensive system prompt with user context.
     */
    protected function buildSystemPrompt($user, string $language = 'en'): string
    {
        $now = Carbon::now()->format('l, F j, Y \a\t H:i');
        
        $languageInstruction = $language === 'de' 
            ? "**WICHTIG**: Antworte auf Deutsch. Alle Antworten müssen auf Deutsch sein."
            : "**IMPORTANT**: Respond in English. All responses must be in English.";
        
        return <<<PROMPT
You are the **Landeseiten AI Assistant** — an intelligent helper for the LSM (Landeseiten Maintenance) platform, a WordPress site management system used by a web agency to maintain multiple client websites.

## Current Context
- **User**: {$user->name} ({$user->email})
- **Role**: {$user->role}
- **Date/Time**: {$now}

## Language
{$languageInstruction}

## About LSM Platform
LSM is an internal management platform for web agencies that maintain WordPress websites. It provides:

- **🌐 Website Health Monitoring** — Real-time status, security, plugin updates
- **👥 Team Coordination** — Managers assign projects to developers
- **⏱️ Time Tracking** — Log work time, approve timesheets, generate invoices
- **🔐 Credential Vault** — Secure password storage per project
- **📋 Task Management** — Todos with priorities and assignees
- **🎫 Support Tickets** — Track client issues
- **📊 Reporting** — Maintenance reports and invoices

## Your Capabilities
You have access to tools that let you perform almost any action an admin can do:

### Information Tools
- `get-dashboard` — Overview of stats, todos, time today
- `list-projects` — Search/filter WordPress projects
- `get-project` — Detailed project info
- `list-todos` — View tasks (filter by status/priority/project)
- `list-time-entries` — View time logs
- `list-invoices` — View billing
- `list-team` — View team members
- `list-support-tickets` — View support requests
- `list-tags` — View project categories
- `list-resources` — View project files/links

### Action Tools
- `create-todo` — Add new task
- `update-todo` — Change status/priority/assignee/due date
- `complete-todo` — Mark task done
- `delete-todo` — Remove task
- `create-project` — Add new WordPress site
- `update-project` — Modify project details
- `create-time-entry` — Log manual time
- `start-timer` / `stop-timer` — Track work in real-time
- `create-support-ticket` — Open new support request

### WordPress Remote Actions
- `wp-login` — Generate one-click admin login link
- `wp-clear-cache` — Clear all site caches
- `wp-maintenance-on` / `wp-maintenance-off` — Toggle maintenance mode
- `wp-get-updates` — Check for WordPress/plugin updates
- `wp-update-plugins` — Update ALL plugins to latest versions
- `wp-update-core` — Update WordPress core
- `wp-optimize-database` — Optimize database tables
- `wp-emergency` — Emergency recovery (disable-plugins, restore-plugins, emergency-recovery)
- `wp-check-connections` — Find which sites ACTUALLY have the LSM plugin working (tests real connections)

### Smart Analytics
- `get-team-workload` — See todos/time per dev, find "least busy" developer
- `get-team-availability` — Who's working, who's not tracking time

### Todo Templates
- `list-todo-templates` — See available templates (maintenance, security_audit, etc.)
- `apply-todo-template` — Add template todos to a project with optional auto-assignment

### Bulk Operations (fast, avoids rate limits)
- `bulk-assign-developers` — ONLY for users with role="developer". Assign/remove DEVELOPERS to projects.
- `bulk-assign-managers` — ONLY for users with role="manager". Assign/remove PROJECT MANAGERS (PMs) to projects.
- `bulk-wp-action` — Run WordPress actions on MULTIPLE sites at once

**IMPORTANT**: A user with role="manager" is a PM and must use `bulk-assign-managers`. A user with role="developer" must use `bulk-assign-developers`. Never mix them!

## Status Meanings

### Health Status
| Status | Meaning |
|--------|---------|
| 🟢 online | Site is healthy and accessible |
| 🔴 offline | Site is unreachable |
| 🟡 maintenance | Site is in maintenance mode |
| 🔄 updating | Updates are being applied |
| ⚠️ down_error | Site has errors |

### Security Status
| Status | Meaning |
|--------|---------|
| ✅ secure | No security issues |
| 👁️ monitoring | Under observation |
| ⚠️ at_risk | Vulnerabilities detected |
| 🟠 compromised | Security breach possible |
| 🚨 hacked | Confirmed compromise |

### User Roles
| Role | Permissions |
|------|-------------|
| admin | Full access, create users, manage billing |
| manager | Manage assigned projects, approve timesheets |
| developer | Work on assigned projects, log time |
| viewer | Read-only access to assigned projects |

## Guidelines

1. **Be helpful and proactive** — Anticipate what the user needs
2. **Use tools liberally** — Don't guess, look up data using tools
3. **Remember context** — Reference earlier parts of conversation
4. **Be concise** — No fluff, get to the point
5. **Use markdown** — Headers, lists, bold for readability
6. **Use emoji sparingly** — To highlight status (🟢 good, 🔴 bad, ⚠️ warning)
7. **Never reveal passwords** — credentials are encrypted, don't try to access them
8. **Acknowledge limitations** — If you can't do something, say so

## Example Interactions

**User**: "Which sites are offline?"
→ Use `list-projects` with `health_status: offline`

**User**: "Create a todo for BAVIE project: Update plugins"
→ First use `list-projects` to find BAVIE, then `create-todo` with the project ID

**User**: "Start tracking time for project 5"
→ Use `start-timer` with `project_id: 5`

**User**: "Show me Bojan's time entries for this week"
→ First get Bojan's user ID with `list-team`, then use `list-time-entries` with that ID

**User**: "Update that todo to be high priority"
→ Use context from previous messages to get the todo ID, then use `update-todo`

**User**: "Who's not working right now?"
→ Use `get-team-availability` to show who's tracking time and who isn't

**User**: "Find the dev with least todos"
→ Use `get-team-workload` which shows workload stats and identifies least busy

**User**: "Add maintenance todos to BAVIE project"
→ First find BAVIE project ID, then use `apply-todo-template` with template="maintenance"

**User**: "Add security audit to project 5, assign to dev with least work"
→ Use `apply-todo-template` with project_id=5, template="security_audit", auto_assign=true

**User**: "Assign Bojan as PM and add Ana and Marko as developers to project XXXX"
→ First find project ID with `list-projects`, then use `update-project` with:
  - `manager_name: "Bojan"` for the PM
  - `developer_names: ["Ana", "Marko"]` for the developers (array of names)
  Note: Use `developer_names` or `developer_ids` (arrays) for developers, use `add_developer_names` to add without removing existing

**User**: "Assign a developer to all projects that don't have one"
→ Use `bulk-assign-developers` with mode="unassigned" and strategy="round_robin" or "least_busy"
  This is MUCH faster than updating projects one by one and avoids rate limits!

**User**: "Randomly assign developers to each project"
→ Use `bulk-assign-developers` with mode="all" and strategy="random"

**User**: "Remove the PM from project XXXX"
→ Use `update-project` with project_id and `clear_manager: true`

**User**: "Remove all developers from project 5"
→ Use `update-project` with project_id=5 and `clear_developers: true`

**User**: "Remove Ana from project XXXX"
→ Use `update-project` with `remove_developer_names: ["Ana"]`

**User**: "Update plugins on project XXXX"
→ Use `wp-update-plugins` with the project ID

**User**: "Update plugins on all projects"
→ Use `bulk-wp-action` with action="update-plugins" and mode="all"

**User**: "Clear cache on all online sites"
→ Use `bulk-wp-action` with action="clear-cache" and mode="online"

**User**: "Enable maintenance on all projects"
→ Use `bulk-wp-action` with action="enable-maintenance" and mode="all"

**User**: "Remove all developers from all projects"
→ Use `bulk-assign-developers` with action="clear", target="developers", mode="all"

**User**: "The site is broken, run emergency recovery"
→ Use `wp-emergency` with action="emergency-recovery"

You are a trusted assistant. Help the team be more productive!
PROMPT;
    }

    /**
     * Get tool definitions for Claude.
     */
    protected function getToolDefinitions(): array
    {
        return [
            // Dashboard
            [
                'name' => 'get-dashboard',
                'description' => 'Get the user\'s dashboard summary including project stats, pending todos, time tracked today, and notifications.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => new \stdClass(),
                    'required' => [],
                ],
            ],
            // Projects
            [
                'name' => 'list-projects',
                'description' => 'List WordPress projects. Can filter by health status, security status, or search by name/URL.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'health_status' => [
                            'type' => 'string',
                            'description' => 'Filter by health: online, offline, maintenance, updating, down_error',
                        ],
                        'security_status' => [
                            'type' => 'string',
                            'description' => 'Filter by security: secure, monitoring, at_risk, compromised, hacked',
                        ],
                        'search' => [
                            'type' => 'string',
                            'description' => 'Search projects by name or URL',
                        ],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => 'get-project',
                'description' => 'Get detailed information about a specific project including health, todos, and WordPress info.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => [
                            'type' => 'integer',
                            'description' => 'The ID of the project',
                        ],
                    ],
                    'required' => ['project_id'],
                ],
            ],
            [
                'name' => 'create-project',
                'description' => 'Create a new WordPress project. Requires name and URL.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => [
                            'type' => 'string',
                            'description' => 'The project/client name',
                        ],
                        'url' => [
                            'type' => 'string',
                            'description' => 'The WordPress site URL',
                        ],
                        'manager_id' => [
                            'type' => 'integer',
                            'description' => 'User ID of the project manager',
                        ],
                        'manager_name' => [
                            'type' => 'string',
                            'description' => 'Name of manager (will search for matching user)',
                        ],
                    ],
                    'required' => ['name', 'url'],
                ],
            ],
            [
                'name' => 'update-project',
                'description' => 'Update project details. Can add/remove manager and developers. Use clear_manager or clear_developers to REMOVE them.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => [
                            'type' => 'integer',
                            'description' => 'The project ID to update',
                        ],
                        'name' => ['type' => 'string', 'description' => 'New project name'],
                        'url' => ['type' => 'string', 'description' => 'New URL'],
                        'health_status' => ['type' => 'string', 'description' => 'Health status'],
                        'security_status' => ['type' => 'string', 'description' => 'Security status'],
                        // Manager operations
                        'manager_id' => ['type' => 'integer', 'description' => 'New manager ID'],
                        'manager_name' => ['type' => 'string', 'description' => 'New manager name'],
                        'clear_manager' => ['type' => 'boolean', 'description' => 'Set true to REMOVE manager from project'],
                        // Developer SET operations
                        'developer_ids' => [
                            'type' => 'array',
                            'items' => ['type' => 'integer'],
                            'description' => 'Array of developer IDs to assign (REPLACES existing)',
                        ],
                        'developer_names' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'Array of developer names to assign (REPLACES existing)',
                        ],
                        // Developer ADD operations
                        'add_developer_ids' => [
                            'type' => 'array',
                            'items' => ['type' => 'integer'],
                            'description' => 'Array of developer IDs to ADD (keeps existing)',
                        ],
                        'add_developer_names' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'Array of developer names to ADD (keeps existing)',
                        ],
                        // Developer REMOVE operations
                        'clear_developers' => ['type' => 'boolean', 'description' => 'Set true to REMOVE ALL developers'],
                        'remove_developer_ids' => [
                            'type' => 'array',
                            'items' => ['type' => 'integer'],
                            'description' => 'Array of developer IDs to REMOVE',
                        ],
                        'remove_developer_names' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'Array of developer names to REMOVE',
                        ],
                    ],
                    'required' => ['project_id'],
                ],
            ],
            // Todos
            [
                'name' => 'list-todos',
                'description' => 'List todos. By default shows pending assigned todos. Can filter by project, status, priority.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => ['type' => 'integer', 'description' => 'Filter by project ID'],
                        'status' => ['type' => 'string', 'description' => 'Filter: pending, in_progress, completed'],
                        'priority' => ['type' => 'string', 'description' => 'Filter: low, medium, high, urgent'],
                        'all' => ['type' => 'boolean', 'description' => 'If true, show all todos, not just assigned'],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => 'create-todo',
                'description' => 'Create a new todo for a project. If assignee is not on the project team, warns and offers to add them first.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => ['type' => 'integer', 'description' => 'Project ID to create todo in'],
                        'title' => ['type' => 'string', 'description' => 'Todo title'],
                        'description' => ['type' => 'string', 'description' => 'Detailed description'],
                        'priority' => ['type' => 'string', 'description' => 'Priority: low, medium, high, urgent'],
                        'assignee_id' => ['type' => 'integer', 'description' => 'User ID to assign to'],
                        'assignee_name' => ['type' => 'string', 'description' => 'User name to assign to'],
                        'auto_add_to_project' => ['type' => 'boolean', 'description' => 'If true, auto-add assignee to project team if not already on it'],
                    ],
                    'required' => ['project_id', 'title'],
                ],
            ],
            [
                'name' => 'update-todo',
                'description' => 'Update a todo - change status, priority, assignee, due date, title, or description. Warns if new assignee is not on the project team.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'todo_id' => ['type' => 'integer', 'description' => 'Todo ID to update'],
                        'title' => ['type' => 'string', 'description' => 'New title'],
                        'description' => ['type' => 'string', 'description' => 'New description'],
                        'status' => ['type' => 'string', 'description' => 'Status: pending, in_progress, completed'],
                        'priority' => ['type' => 'string', 'description' => 'Priority: low, medium, high, urgent'],
                        'assignee_id' => ['type' => 'integer', 'description' => 'New assignee user ID'],
                        'assignee_name' => ['type' => 'string', 'description' => 'New assignee name'],
                        'due_date' => ['type' => 'string', 'description' => 'Due date YYYY-MM-DD'],
                        'auto_add_to_project' => ['type' => 'boolean', 'description' => 'If true, auto-add assignee to project team if not already on it'],
                    ],
                    'required' => ['todo_id'],
                ],
            ],
            [
                'name' => 'complete-todo',
                'description' => 'Mark a todo as completed.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'todo_id' => ['type' => 'integer', 'description' => 'The todo ID to complete'],
                    ],
                    'required' => ['todo_id'],
                ],
            ],
            [
                'name' => 'delete-todo',
                'description' => 'Delete a todo permanently.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'todo_id' => ['type' => 'integer', 'description' => 'The todo ID to delete'],
                    ],
                    'required' => ['todo_id'],
                ],
            ],
            // Time Tracking
            [
                'name' => 'start-timer',
                'description' => 'Start tracking time for a project. Only one timer can be active at a time.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => ['type' => 'integer', 'description' => 'Project ID to track time for'],
                        'todo_id' => ['type' => 'integer', 'description' => 'Optional todo ID to link to'],
                        'description' => ['type' => 'string', 'description' => 'What you are working on'],
                    ],
                    'required' => ['project_id'],
                ],
            ],
            [
                'name' => 'stop-timer',
                'description' => 'Stop the currently running timer.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'notes' => ['type' => 'string', 'description' => 'Optional notes about work completed'],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => 'list-time-entries',
                'description' => 'List time entries. Can filter by project, user, date range, or show today/this week.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => ['type' => 'integer', 'description' => 'Filter by project'],
                        'user_id' => ['type' => 'integer', 'description' => 'Filter by user (admin only)'],
                        'today' => ['type' => 'boolean', 'description' => 'Show only today\'s entries'],
                        'this_week' => ['type' => 'boolean', 'description' => 'Show this week\'s entries'],
                        'date_from' => ['type' => 'string', 'description' => 'Start date YYYY-MM-DD'],
                        'date_to' => ['type' => 'string', 'description' => 'End date YYYY-MM-DD'],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => 'create-time-entry',
                'description' => 'Manually create a time entry for past work.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => ['type' => 'integer', 'description' => 'Project ID'],
                        'duration_minutes' => ['type' => 'integer', 'description' => 'Duration in minutes'],
                        'hours' => ['type' => 'number', 'description' => 'Duration in hours (alternative)'],
                        'date' => ['type' => 'string', 'description' => 'Date YYYY-MM-DD (defaults to today)'],
                        'start_time' => ['type' => 'string', 'description' => 'Start time HH:MM'],
                        'description' => ['type' => 'string', 'description' => 'Work description'],
                        'todo_id' => ['type' => 'integer', 'description' => 'Link to todo'],
                        'billable' => ['type' => 'boolean', 'description' => 'Is billable (default true)'],
                    ],
                    'required' => ['project_id'],
                ],
            ],
            // Invoices
            [
                'name' => 'list-invoices',
                'description' => 'List invoices. Can filter by status (draft, sent, paid, overdue) or project.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'status' => ['type' => 'string', 'description' => 'Filter: draft, sent, paid, overdue'],
                        'project_id' => ['type' => 'integer', 'description' => 'Filter by project'],
                    ],
                    'required' => [],
                ],
            ],
            // Team
            [
                'name' => 'list-team',
                'description' => 'List all team members with their roles. Can filter by role or search by name.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'role' => ['type' => 'string', 'description' => 'Filter: admin, manager, developer, viewer'],
                        'search' => ['type' => 'string', 'description' => 'Search by name or email'],
                    ],
                    'required' => [],
                ],
            ],
            // Support Tickets
            [
                'name' => 'list-support-tickets',
                'description' => 'List support tickets. Can filter by status, priority, or project.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'status' => ['type' => 'string', 'description' => 'Filter: open, in_progress, resolved, closed'],
                        'project_id' => ['type' => 'integer', 'description' => 'Filter by project'],
                        'priority' => ['type' => 'string', 'description' => 'Filter: low, medium, high, urgent'],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => 'create-support-ticket',
                'description' => 'Create a new support ticket for a project.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => ['type' => 'integer', 'description' => 'Project ID'],
                        'subject' => ['type' => 'string', 'description' => 'Ticket subject'],
                        'description' => ['type' => 'string', 'description' => 'Detailed description'],
                        'priority' => ['type' => 'string', 'description' => 'Priority: low, medium, high, urgent'],
                        'assignee_id' => ['type' => 'integer', 'description' => 'Assign to user ID'],
                        'assignee_name' => ['type' => 'string', 'description' => 'Assign to user name'],
                    ],
                    'required' => ['project_id', 'subject'],
                ],
            ],
            // Tags & Resources
            [
                'name' => 'list-tags',
                'description' => 'List all available tags for categorizing projects.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => new \stdClass(),
                    'required' => [],
                ],
            ],
            [
                'name' => 'list-resources',
                'description' => 'List resources (files, links, documents) attached to a project.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => ['type' => 'integer', 'description' => 'Project ID'],
                    ],
                    'required' => ['project_id'],
                ],
            ],
            // WordPress Actions
            [
                'name' => 'wp-login',
                'description' => 'Generate a one-time SSO login URL for WordPress admin.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => ['type' => 'integer', 'description' => 'Project ID'],
                    ],
                    'required' => ['project_id'],
                ],
            ],
            [
                'name' => 'wp-clear-cache',
                'description' => 'Clear all caches on a WordPress site.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => ['type' => 'integer', 'description' => 'Project ID'],
                    ],
                    'required' => ['project_id'],
                ],
            ],
            [
                'name' => 'wp-maintenance-on',
                'description' => 'Enable maintenance mode on a WordPress site.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => ['type' => 'integer', 'description' => 'Project ID'],
                    ],
                    'required' => ['project_id'],
                ],
            ],
            [
                'name' => 'wp-maintenance-off',
                'description' => 'Disable maintenance mode on a WordPress site.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => ['type' => 'integer', 'description' => 'Project ID'],
                    ],
                    'required' => ['project_id'],
                ],
            ],
            [
                'name' => 'wp-get-updates',
                'description' => 'Check for available WordPress, plugin, and theme updates.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => ['type' => 'integer', 'description' => 'Project ID'],
                    ],
                    'required' => ['project_id'],
                ],
            ],
            [
                'name' => 'wp-update-plugins',
                'description' => 'Update ALL plugins on a WordPress site to their latest versions.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => ['type' => 'integer', 'description' => 'Project ID'],
                    ],
                    'required' => ['project_id'],
                ],
            ],
            [
                'name' => 'wp-update-core',
                'description' => 'Update WordPress core to the latest version.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => ['type' => 'integer', 'description' => 'Project ID'],
                    ],
                    'required' => ['project_id'],
                ],
            ],
            [
                'name' => 'wp-optimize-database',
                'description' => 'Optimize the WordPress database tables to improve performance.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => ['type' => 'integer', 'description' => 'Project ID'],
                    ],
                    'required' => ['project_id'],
                ],
            ],
            [
                'name' => 'wp-emergency',
                'description' => 'Emergency recovery actions. Actions: disable-plugins (disable all), restore-plugins (restore), emergency-recovery (full recovery).',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => ['type' => 'integer', 'description' => 'Project ID'],
                        'action' => [
                            'type' => 'string',
                            'enum' => ['disable-plugins', 'restore-plugins', 'emergency-recovery'],
                            'description' => 'Emergency action to perform',
                        ],
                    ],
                    'required' => ['project_id', 'action'],
                ],
            ],
            [
                'name' => 'wp-check-connections',
                'description' => 'Check which projects have the LSM plugin ACTUALLY installed and working. Tests real connections, not just database status. Use this to find which sites have WordPress remote management enabled.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'limit' => ['type' => 'integer', 'description' => 'Max projects to test (default 20)'],
                        'only_connected' => ['type' => 'boolean', 'description' => 'If true, only show connected sites'],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => 'bulk-wp-action',
                'description' => 'Run WordPress actions on MULTIPLE projects at once. Actions: clear-cache, enable-maintenance, disable-maintenance, update-plugins.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'action' => [
                            'type' => 'string',
                            'enum' => ['clear-cache', 'enable-maintenance', 'disable-maintenance', 'update-plugins'],
                            'description' => 'WordPress action to perform',
                        ],
                        'mode' => [
                            'type' => 'string',
                            'enum' => ['all', 'specific', 'online', 'offline', 'maintenance'],
                            'description' => 'Which projects: all, specific (use project_ids), online, offline, maintenance',
                        ],
                        'project_ids' => [
                            'type' => 'array',
                            'items' => ['type' => 'integer'],
                            'description' => 'Specific project IDs (when mode=specific)',
                        ],
                    ],
                    'required' => ['action'],
                ],
            ],
            // Analytics & Team Intelligence
            [
                'name' => 'get-team-workload',
                'description' => 'Get team workload statistics. Shows active todos per dev, time tracked this week, and identifies the "least busy" developer for assignment.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => new \stdClass(),
                    'required' => [],
                ],
            ],
            [
                'name' => 'get-team-availability',
                'description' => 'Check team availability. Shows who is currently tracking time (working), who hasn\'t started yet today, and who is unavailable.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => new \stdClass(),
                    'required' => [],
                ],
            ],
            // Todo Templates
            [
                'name' => 'list-todo-templates',
                'description' => 'List available todo templates. Templates are predefined sets of todos like maintenance, security_audit, onboarding, performance.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => new \stdClass(),
                    'required' => [],
                ],
            ],
            [
                'name' => 'apply-todo-template',
                'description' => 'Apply a predefined todo template to a project. Creates all todos from the template. Templates: maintenance, security_audit, onboarding, performance.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => ['type' => 'integer', 'description' => 'Project ID to apply template to'],
                        'template' => ['type' => 'string', 'description' => 'Template name: maintenance, security_audit, onboarding, performance'],
                        'assignee_id' => ['type' => 'integer', 'description' => 'User ID to assign all todos to'],
                        'assignee_name' => ['type' => 'string', 'description' => 'User name to assign to'],
                        'auto_assign' => ['type' => 'boolean', 'description' => 'Auto-assign to dev with fewest active todos'],
                    ],
                    'required' => ['project_id', 'template'],
                ],
            ],
            // Bulk Operations
            [
                'name' => 'bulk-assign-developers',
                'description' => 'Bulk operations for project assignments. Can ASSIGN (action=assign) or REMOVE (action=clear) developers/managers from multiple projects.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'action' => [
                            'type' => 'string',
                            'enum' => ['assign', 'clear'],
                            'description' => 'Action: assign to add developers, clear to REMOVE them',
                        ],
                        'target' => [
                            'type' => 'string',
                            'enum' => ['developers', 'manager', 'both'],
                            'description' => 'What to clear (for action=clear): developers, manager, or both',
                        ],
                        'mode' => [
                            'type' => 'string',
                            'enum' => ['all', 'specific', 'unassigned', 'assigned'],
                            'description' => 'Which projects: all, specific (use project_ids), unassigned (no devs), assigned (has devs)',
                        ],
                        'project_ids' => [
                            'type' => 'array',
                            'items' => ['type' => 'integer'],
                            'description' => 'Specific project IDs when mode=specific',
                        ],
                        'developer_ids' => [
                            'type' => 'array',
                            'items' => ['type' => 'integer'],
                            'description' => 'Developer IDs to assign (for action=assign)',
                        ],
                        'developer_names' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'Developer names to assign (for action=assign)',
                        ],
                        'strategy' => [
                            'type' => 'string',
                            'enum' => ['round_robin', 'least_busy', 'random'],
                            'description' => 'Distribution strategy (for action=assign): round_robin, least_busy, random',
                        ],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => 'bulk-assign-managers',
                'description' => 'Bulk PM operations. Use action="assign" to add PMs (with strategies), action="clear" to REMOVE all PMs.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'action' => [
                            'type' => 'string',
                            'enum' => ['assign', 'clear'],
                            'description' => 'Action: assign to add PMs, clear to REMOVE all PMs',
                        ],
                        'mode' => [
                            'type' => 'string',
                            'enum' => ['all', 'unassigned', 'assigned', 'specific'],
                            'description' => 'Which projects: all, unassigned (no PM), assigned (has PM), specific',
                        ],
                        'project_ids' => [
                            'type' => 'array',
                            'items' => ['type' => 'integer'],
                            'description' => 'Specific project IDs when mode=specific',
                        ],
                        'manager_ids' => [
                            'type' => 'array',
                            'items' => ['type' => 'integer'],
                            'description' => 'Manager user IDs to assign (for action=assign)',
                        ],
                        'manager_names' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'Manager names to assign (for action=assign)',
                        ],
                        'strategy' => [
                            'type' => 'string',
                            'enum' => ['round_robin', 'random', 'weighted'],
                            'description' => 'Distribution (for action=assign): round_robin, random, weighted',
                        ],
                        'weights' => [
                            'type' => 'array',
                            'items' => ['type' => 'integer'],
                            'description' => 'Weight % for each manager (for strategy=weighted)',
                        ],
                    ],
                    'required' => [],
                ],
            ],
            // Export
            [
                'name' => 'generate-pdf',
                'description' => 'Generate a PDF report with download link. Use filter for subsets like missing_url, missing_manager, at_risk.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'report_type' => [
                            'type' => 'string',
                            'enum' => ['team', 'projects', 'project-details', 'todos'],
                            'description' => 'Report type: team, projects, project-details, todos',
                        ],
                        'project_id' => [
                            'type' => 'integer',
                            'description' => 'Project ID (required for project-details)',
                        ],
                        'filter' => [
                            'type' => 'string',
                            'description' => 'Filter: missing_url, missing_manager, at_risk, offline, maintenance, connected, not_connected, urgent, overdue, unassigned',
                        ],
                    ],
                    'required' => ['report_type'],
                ],
            ],
        ];
    }

    /**
     * Execute a tool and return the result.
     */
    protected function executeTool(string $toolName, array $input, $user): string
    {
        // Map tool names to MCP tool classes
        $toolMap = [
            // Dashboard
            'get-dashboard' => \App\Mcp\Tools\GetDashboardTool::class,
            // Projects
            'list-projects' => \App\Mcp\Tools\ListProjectsTool::class,
            'get-project' => \App\Mcp\Tools\GetProjectTool::class,
            'create-project' => \App\Mcp\Tools\CreateProjectTool::class,
            'update-project' => \App\Mcp\Tools\UpdateProjectTool::class,
            // Todos
            'list-todos' => \App\Mcp\Tools\ListTodosTool::class,
            'create-todo' => \App\Mcp\Tools\CreateTodoTool::class,
            'update-todo' => \App\Mcp\Tools\UpdateTodoTool::class,
            'complete-todo' => \App\Mcp\Tools\CompleteTodoTool::class,
            'delete-todo' => \App\Mcp\Tools\DeleteTodoTool::class,
            // Time Tracking
            'start-timer' => \App\Mcp\Tools\StartTimerTool::class,
            'stop-timer' => \App\Mcp\Tools\StopTimerTool::class,
            'list-time-entries' => \App\Mcp\Tools\ListTimeEntriesTool::class,
            'create-time-entry' => \App\Mcp\Tools\CreateTimeEntryTool::class,
            // Invoices
            'list-invoices' => \App\Mcp\Tools\ListInvoicesTool::class,
            // Team
            'list-team' => \App\Mcp\Tools\ListTeamTool::class,
            // Support Tickets
            'list-support-tickets' => \App\Mcp\Tools\ListSupportTicketsTool::class,
            'create-support-ticket' => \App\Mcp\Tools\CreateSupportTicketTool::class,
            // Tags & Resources
            'list-tags' => \App\Mcp\Tools\ListTagsTool::class,
            'list-resources' => \App\Mcp\Tools\ListResourcesTool::class,
            // WordPress
            'wp-login' => \App\Mcp\Tools\WpLoginTool::class,
            'wp-clear-cache' => \App\Mcp\Tools\WpClearCacheTool::class,
            'wp-maintenance-on' => \App\Mcp\Tools\WpEnableMaintenanceTool::class,
            'wp-maintenance-off' => \App\Mcp\Tools\WpDisableMaintenanceTool::class,
            'wp-get-updates' => \App\Mcp\Tools\WpGetUpdatesTool::class,
            'wp-update-plugins' => \App\Mcp\Tools\WpUpdatePluginsTool::class,
            'wp-update-core' => \App\Mcp\Tools\WpUpdateCoreTool::class,
            'wp-optimize-database' => \App\Mcp\Tools\WpOptimizeDatabaseTool::class,
            'wp-emergency' => \App\Mcp\Tools\WpEmergencyTool::class,
            'wp-check-connections' => \App\Mcp\Tools\WpCheckConnectionsTool::class,
            // Analytics & Team Intelligence
            'get-team-workload' => \App\Mcp\Tools\GetTeamWorkloadTool::class,
            'get-team-availability' => \App\Mcp\Tools\GetTeamAvailabilityTool::class,
            // Todo Templates
            'list-todo-templates' => \App\Mcp\Tools\ListTodoTemplatesTool::class,
            'apply-todo-template' => \App\Mcp\Tools\ApplyTodoTemplateTool::class,
            // Bulk Operations
            'bulk-assign-developers' => \App\Mcp\Tools\BulkAssignDevelopersTool::class,
            'bulk-assign-managers' => \App\Mcp\Tools\BulkAssignManagersTool::class,
            'bulk-wp-action' => \App\Mcp\Tools\BulkWpActionTool::class,
            // Export
            'generate-pdf' => \App\Mcp\Tools\GeneratePdfTool::class,
        ];

        if (!isset($toolMap[$toolName])) {
            return "Error: Unknown tool '{$toolName}'";
        }

        try {
            $toolClass = $toolMap[$toolName];
            $tool = app($toolClass);

            // Create MCP Request with arguments
            $request = new \Laravel\Mcp\Request($input);

            $response = $tool->handle($request);

            // Extract text from response
            $content = $response->content();
            
            if (method_exists($content, 'text')) {
                return $content->text();
            }
            
            return (string) $content;

        } catch (\Exception $e) {
            Log::error('Tool execution error', [
                'tool' => $toolName,
                'error' => $e->getMessage(),
            ]);

            return "Error executing {$toolName}: " . $e->getMessage();
        }
    }
}
