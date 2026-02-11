<?php

namespace App\Mcp;

use Laravel\Mcp\Server;

// Resources
use App\Mcp\Resources\DashboardResource;
use App\Mcp\Resources\MyTodosResource;
use App\Mcp\Resources\ProjectsResource;
use App\Mcp\Resources\SitesAtRiskResource;
use App\Mcp\Resources\TimeTodayResource;
use App\Mcp\Resources\VaultResource;

// Prompts
use App\Mcp\Prompts\MorningBriefingPrompt;
use App\Mcp\Prompts\WeeklyStatusPrompt;

// Tools
use App\Mcp\Tools\ApplyTodoTemplateTool;
use App\Mcp\Tools\BulkAssignDevelopersTool;
use App\Mcp\Tools\BulkAssignManagersTool;
use App\Mcp\Tools\BulkWpActionTool;
use App\Mcp\Tools\CompleteTodoTool;
use App\Mcp\Tools\CreateProjectTool;
use App\Mcp\Tools\CreateSupportTicketTool;
use App\Mcp\Tools\CreateTimeEntryTool;
use App\Mcp\Tools\CreateTodoTool;
use App\Mcp\Tools\DeleteTodoTool;
use App\Mcp\Tools\GeneratePdfTool;
use App\Mcp\Tools\GetDashboardTool;
use App\Mcp\Tools\GetProjectTool;
use App\Mcp\Tools\GetTeamAvailabilityTool;
use App\Mcp\Tools\GetTeamWorkloadTool;
use App\Mcp\Tools\ListInvoicesTool;
use App\Mcp\Tools\ListProjectsTool;
use App\Mcp\Tools\ListResourcesTool;
use App\Mcp\Tools\ListSupportTicketsTool;
use App\Mcp\Tools\ListTagsTool;
use App\Mcp\Tools\ListTeamTool;
use App\Mcp\Tools\ListTimeEntriesTool;
use App\Mcp\Tools\ListTodoTemplatesTool;
use App\Mcp\Tools\ListTodosTool;
use App\Mcp\Tools\StartTimerTool;
use App\Mcp\Tools\StopTimerTool;
use App\Mcp\Tools\UpdateProjectTool;
use App\Mcp\Tools\UpdateTodoTool;
use App\Mcp\Tools\WpCheckConnectionsTool;
use App\Mcp\Tools\WpClearCacheTool;
use App\Mcp\Tools\WpClearPhpErrorsTool;
use App\Mcp\Tools\WpCreateBackupTool;
use App\Mcp\Tools\WpDisableMaintenanceTool;
use App\Mcp\Tools\WpDownloadBackupTool;
use App\Mcp\Tools\WpEmergencyTool;
use App\Mcp\Tools\WpEnableMaintenanceTool;
use App\Mcp\Tools\WpGetPhpErrorsTool;
use App\Mcp\Tools\WpGetUpdatesTool;
use App\Mcp\Tools\WpListBackupsTool;
use App\Mcp\Tools\WpLoginTool;
use App\Mcp\Tools\WpOptimizeDatabaseTool;
use App\Mcp\Tools\WpRestoreBackupTool;
use App\Mcp\Tools\WpUpdateCoreTool;
use App\Mcp\Tools\WpUpdatePluginsTool;

class LsmServer extends Server
{
    protected array $resources = [
        DashboardResource::class,
        MyTodosResource::class,
        ProjectsResource::class,
        SitesAtRiskResource::class,
        TimeTodayResource::class,
        VaultResource::class,
    ];

    protected array $prompts = [
        MorningBriefingPrompt::class,
        WeeklyStatusPrompt::class,
    ];

    protected array $tools = [
        ApplyTodoTemplateTool::class,
        BulkAssignDevelopersTool::class,
        BulkAssignManagersTool::class,
        BulkWpActionTool::class,
        CompleteTodoTool::class,
        CreateProjectTool::class,
        CreateSupportTicketTool::class,
        CreateTimeEntryTool::class,
        CreateTodoTool::class,
        DeleteTodoTool::class,
        GeneratePdfTool::class,
        GetDashboardTool::class,
        GetProjectTool::class,
        GetTeamAvailabilityTool::class,
        GetTeamWorkloadTool::class,
        ListInvoicesTool::class,
        ListProjectsTool::class,
        ListResourcesTool::class,
        ListSupportTicketsTool::class,
        ListTagsTool::class,
        ListTeamTool::class,
        ListTimeEntriesTool::class,
        ListTodoTemplatesTool::class,
        ListTodosTool::class,
        StartTimerTool::class,
        StopTimerTool::class,
        UpdateProjectTool::class,
        UpdateTodoTool::class,
        WpCheckConnectionsTool::class,
        WpClearCacheTool::class,
        WpClearPhpErrorsTool::class,
        WpCreateBackupTool::class,
        WpDisableMaintenanceTool::class,
        WpDownloadBackupTool::class,
        WpEmergencyTool::class,
        WpEnableMaintenanceTool::class,
        WpGetPhpErrorsTool::class,
        WpGetUpdatesTool::class,
        WpListBackupsTool::class,
        WpLoginTool::class,
        WpOptimizeDatabaseTool::class,
        WpRestoreBackupTool::class,
        WpUpdateCoreTool::class,
        WpUpdatePluginsTool::class,
    ];
}
