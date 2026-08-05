<?php

use App\Mcp\Servers\LsmServer;
use Laravel\Mcp\Facades\Mcp;

/*
|--------------------------------------------------------------------------
| MCP (Model Context Protocol) Routes
|--------------------------------------------------------------------------
|
| These routes expose the LSM platform to AI clients via MCP.
| The MCP server allows AI assistants to:
| - Query project status and health data
| - Manage todos and time tracking
| - Perform WordPress remote actions
|
| Authentication: Uses Sanctum bearer token
| Transport: HTTP (SSE-compatible)
|
*/

// Single registration point for the MCP server. Previously this competed with
// App\Providers\McpServiceProvider, which registered the same path with no
// middleware at all — see docs/superpowers/plans/2026-08-04-mcp-integration-tokens.md.
if (config('mcp.enabled', true)) {
    Mcp::web(config('mcp.route.path', '/mcp'), LsmServer::class)
        ->middleware(config('mcp.route.middleware', ['auth:sanctum']));

    // Local stdio transport for CLI clients.
    Mcp::local('lsm', LsmServer::class);
}
