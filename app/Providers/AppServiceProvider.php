<?php

namespace App\Providers;

use App\Models\Invoice;
use App\Models\TimeEntry;
use App\Models\Timesheet;
use App\Policies\InvoicePolicy;
use App\Policies\TimeEntryPolicy;
use App\Policies\TimesheetPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // Rate limiter for search API - 30 requests per minute
        RateLimiter::for('search', function (Request $request) {
            return Limit::perMinute(30)->by($request->user()?->id ?: $request->ip());
        });

        // Rate limiter for WP-plugin ticket routes - 30/min per site API key
        // (falls back to IP when the key is missing so unauthenticated
        // probing can't dodge the limit)
        RateLimiter::for('lsm-plugin', function (Request $request) {
            $key = $request->header('X-LSM-Key');

            return Limit::perMinute(30)
                ->by($key ? 'lsm-key:' . hash('sha256', $key) : 'lsm-ip:' . $request->ip());
        });

        // Register time tracking policies
        Gate::policy(TimeEntry::class, TimeEntryPolicy::class);
        Gate::policy(Timesheet::class, TimesheetPolicy::class);
        Gate::policy(Invoice::class, InvoicePolicy::class);
    }
}
