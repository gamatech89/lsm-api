<?php

namespace App\Mcp\Servers;

use App\Mcp\Prompts\MorningBriefingPrompt;
use App\Mcp\Prompts\WeeklyStatusPrompt;
use App\Mcp\Resources\DashboardResource;
use App\Mcp\Resources\MyTodosResource;
use App\Mcp\Resources\ProjectsResource;
use App\Mcp\Resources\SitesAtRiskResource;
use App\Mcp\Resources\TimeTodayResource;
use App\Mcp\Resources\VaultResource;
use App\Mcp\Tools\CompleteTodoTool;
use App\Mcp\Tools\CreateTodoTool;
use App\Mcp\Tools\GetDashboardTool;
use App\Mcp\Tools\GetProjectTool;
use App\Mcp\Tools\ListProjectsTool;
use App\Mcp\Tools\ListTodosTool;
use App\Mcp\Tools\StartTimerTool;
use App\Mcp\Tools\StopTimerTool;
use App\Mcp\Tools\WpClearCacheTool;
use App\Mcp\Tools\WpEnableMaintenanceTool;
use App\Mcp\Tools\WpDisableMaintenanceTool;
use App\Mcp\Tools\WpGetUpdatesTool;
use App\Mcp\Tools\WpLoginTool;
use Laravel\Mcp\Server;

class LsmServer extends Server
{
    /**
     * The MCP server's name.
     */
    protected string $name = 'LSM Platform';

    /**
     * The MCP server's version.
     */
    protected string $version = '1.0.0';

    /**
     * The MCP server's instructions for the LLM.
     */
    protected string $instructions = <<<'MARKDOWN'
    # LSM Platform - Landeseiten Maintenance

    This MCP server provides AI access to the LSM (Landeseiten Maintenance) platform for managing WordPress websites.

    ## Capabilities

    ### Project Management
    - List and search WordPress projects
    - View project health status and details
    - Monitor site security and uptime

    ### WordPress Remote Actions
    - Generate WordPress admin login links (SSO)
    - Enable/disable maintenance mode
    - Clear caches
    - Check available updates

    ### Task Management
    - View and manage todos
    - Create new tasks
    - Mark tasks as completed

    ### Time Tracking
    - Start and stop time tracking
    - View today's time entries
    - Log time for projects

    ## Access Control
    All actions respect the authenticated user's role and permissions:
    - **Admin**: Full access to all projects and features
    - **Manager**: Access to assigned projects + team approval workflows
    - **Developer**: Access to assigned projects + personal time tracking

    ## Best Practices
    1. Always check the user's dashboard first for an overview
    2. Use `list-projects` with filters to find specific sites
    3. Check site health before performing maintenance actions
    4. Log time when working on projects
    MARKDOWN;

    /**
     * The tools registered with this MCP server.
     *
     * @var array<int, class-string<\Laravel\Mcp\Server\Tool>>
     */
    protected array $tools = [
        // Dashboard
        GetDashboardTool::class,

        // Projects
        ListProjectsTool::class,
        GetProjectTool::class,

        // WordPress Remote Actions
        WpLoginTool::class,
        WpClearCacheTool::class,
        WpEnableMaintenanceTool::class,
        WpDisableMaintenanceTool::class,
        WpGetUpdatesTool::class,

        // Todos
        ListTodosTool::class,
        CreateTodoTool::class,
        CompleteTodoTool::class,

        // Time Tracking
        StartTimerTool::class,
        StopTimerTool::class,
    ];

    /**
     * The resources registered with this MCP server.
     *
     * @var array<int, class-string<\Laravel\Mcp\Server\Resource>>
     */
    protected array $resources = [
        DashboardResource::class,
        ProjectsResource::class,
        MyTodosResource::class,
        TimeTodayResource::class,
        VaultResource::class,
        SitesAtRiskResource::class,
    ];

    /**
     * The prompts registered with this MCP server.
     *
     * @var array<int, class-string<\Laravel\Mcp\Server\Prompt>>
     */
    protected array $prompts = [
        MorningBriefingPrompt::class,
        WeeklyStatusPrompt::class,
    ];
}
