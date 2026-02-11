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

// Web MCP endpoint - requires Sanctum auth
Mcp::web('/mcp', LsmServer::class)
    ->middleware(['auth:sanctum']);

// Local MCP endpoint (for CLI tools like Claude Desktop stdio mode)
Mcp::local('lsm', LsmServer::class);
