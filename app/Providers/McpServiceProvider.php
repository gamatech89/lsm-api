<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Laravel\Mcp\Facades\Mcp;
use App\Mcp\LsmServer;

class McpServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        if (config('mcp.enabled', true)) {
            Mcp::web(
                config('mcp.route.path', '/mcp'),
                LsmServer::class
            );
        }
    }
}
