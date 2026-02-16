<?php

use App\Http\Controllers\Api\V1;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| REST API routes for mobile and SPA clients.
| All routes are prefixed with /api/v1
|
*/

Route::prefix('v1')->name('api.v1.')->group(function () {

    // =====================================================
    // PUBLIC ROUTES (No authentication required)
    // =====================================================
    
    Route::post('/login', [V1\AuthController::class, 'login'])
        ->middleware('throttle:5,1')
        ->name('login');

    Route::post('/reset-password', [V1\AuthController::class, 'resetPassword'])
        ->middleware('throttle:5,1')
        ->name('password.reset');
    
    // Public credential share verification (if needed for mobile)
    // Route::post('/share/credential/{token}/verify', [V1\CredentialShareController::class, 'verify']);
    
    // VAULT SHARING (Public Access)
    Route::get('/share/{token}', [V1\CredentialShareController::class, 'show'])->name('share.show');
    Route::post('/share/{token}/access', [V1\CredentialShareController::class, 'access'])->name('share.access');

    // WEBHOOKS (Public - authenticated via API key in payload)
    Route::post('/webhooks/support-ticket', [V1\SupportTicketController::class, 'receiveFromPlugin'])
        ->name('webhooks.support-ticket');

    // =====================================================
    // PROTECTED ROUTES (Requires Sanctum authentication)
    // =====================================================
    
    Route::middleware('auth:sanctum')->group(function () {
        
        // -------------------------------------------------
        // AUTHENTICATION
        // -------------------------------------------------
        Route::post('/logout', [V1\AuthController::class, 'logout'])->name('logout');
        Route::post('/logout-all', [V1\AuthController::class, 'logoutAll'])->name('logout-all');
        Route::get('/user', [V1\AuthController::class, 'user'])->name('user');
        Route::put('/user/profile', [V1\AuthController::class, 'updateProfile'])->name('user.update-profile');
        Route::put('/user/billing', [V1\AuthController::class, 'updateBilling'])->name('user.update-billing');
        Route::post('/refresh-token', [V1\AuthController::class, 'refresh'])->name('refresh');

        // -------------------------------------------------
        // DASHBOARD
        // -------------------------------------------------
        Route::get('/dashboard', [V1\DashboardController::class, 'index'])->name('dashboard');
        Route::get('/dashboard/stats', [V1\DashboardController::class, 'stats'])->name('dashboard.stats');

        // -------------------------------------------------
        // AI CHAT (Admin only)
        // -------------------------------------------------
        Route::post('/ai/chat', [V1\AiChatController::class, 'chat'])
            ->middleware('role:admin')
            ->name('ai.chat');

        // -------------------------------------------------
        // SETTINGS (Admin only)
        // -------------------------------------------------
        Route::get('/settings', [V1\SettingsController::class, 'index'])
            ->middleware('role:admin')
            ->name('settings.index');
        Route::put('/settings', [V1\SettingsController::class, 'update'])
            ->middleware('role:admin')
            ->name('settings.update');

        // -------------------------------------------------
        // PROJECTS
        // -------------------------------------------------
        Route::apiResource('projects', V1\ProjectController::class);
        Route::post('/projects/{project}/check-health', [V1\ProjectController::class, 'checkHealth'])
            ->name('projects.check-health');
        Route::get('/projects/{project}/uptime-stats', [V1\ProjectController::class, 'uptimeStats'])
            ->name('projects.uptime-stats');
        Route::get('/projects-filter-options', [V1\ProjectController::class, 'filterOptions'])
            ->name('projects.filter-options');
        Route::get('/projects-stats', [V1\ProjectController::class, 'stats'])
            ->name('projects.stats');
        Route::get('/projects-quick-search', [V1\ProjectController::class, 'quickSearch'])
            ->name('projects.quick-search');

        // -------------------------------------------------
        // LSM (Landeseiten Maintenance WordPress Plugin)
        // -------------------------------------------------
        Route::prefix('projects/{project}/lsm')->name('projects.lsm.')->group(function () {
            // Status & Health
            Route::get('/status', [V1\LsmController::class, 'status'])->name('status');
            Route::get('/health', [V1\LsmController::class, 'health'])->name('health');
            Route::get('/plugins', [V1\LsmController::class, 'getPlugins'])->name('plugins');
            Route::get('/themes', [V1\LsmController::class, 'getThemes'])->name('themes');
            
            // SSO Login
            Route::post('/login-token', [V1\LsmController::class, 'generateLoginToken'])->name('login-token');
            
            // Cache & Optimization
            Route::post('/clear-cache', [V1\LsmController::class, 'clearCache'])->name('clear-cache');
            Route::post('/optimize-db', [V1\LsmController::class, 'optimizeDatabase'])->name('optimize-db');
            Route::post('/cleanup-db', [V1\LsmController::class, 'cleanupDatabase'])->name('cleanup-db');
            Route::get('/database-stats', [V1\LsmController::class, 'getDatabaseStats'])->name('database-stats');
            Route::post('/flush-rewrite', [V1\LsmController::class, 'flushRewrite'])->name('flush-rewrite');
            
            // Updates
            Route::get('/updates', [V1\LsmController::class, 'getUpdates'])->name('updates');
            Route::post('/update-plugin', [V1\LsmController::class, 'updatePlugin'])->name('update-plugin');
            Route::post('/update-all-plugins', [V1\LsmController::class, 'updateAllPlugins'])->name('update-all-plugins');
            Route::post('/update-core', [V1\LsmController::class, 'updateCore'])->name('update-core');
            
            // Plugin management
            Route::post('/activate-plugin', [V1\LsmController::class, 'activatePlugin'])->name('activate-plugin');
            Route::post('/deactivate-plugin', [V1\LsmController::class, 'deactivatePlugin'])->name('deactivate-plugin');
            Route::post('/delete-plugin', [V1\LsmController::class, 'deletePlugin'])->name('delete-plugin');
            
            // Recovery/Killswitch
            Route::get('/recovery-status', [V1\LsmController::class, 'recoveryStatus'])->name('recovery-status');
            Route::post('/enable-maintenance', [V1\LsmController::class, 'enableMaintenance'])->name('enable-maintenance');
            Route::post('/disable-maintenance', [V1\LsmController::class, 'disableMaintenance'])->name('disable-maintenance');
            Route::post('/disable-plugins', [V1\LsmController::class, 'disablePlugins'])->name('disable-plugins');
            Route::post('/restore-plugins', [V1\LsmController::class, 'restorePlugins'])->name('restore-plugins');
            Route::post('/switch-theme', [V1\LsmController::class, 'switchTheme'])->name('switch-theme');
            Route::post('/activate-theme', [V1\LsmController::class, 'activateTheme'])->name('activate-theme');
            Route::post('/emergency-recovery', [V1\LsmController::class, 'emergencyRecovery'])->name('emergency-recovery');
            
            // Download Plugin
            Route::get('/download-plugin', [V1\LsmController::class, 'downloadPlugin'])->name('download-plugin');
            
            // PHP Error Monitoring (fetch from WordPress)
            Route::get('/php-errors', [V1\LsmController::class, 'getPhpErrors'])->name('php-errors');
            Route::get('/php-errors/stats', [V1\LsmController::class, 'getPhpErrorStats'])->name('php-errors.stats');
            Route::post('/php-errors/sync', [V1\LsmController::class, 'syncPhpErrors'])->name('php-errors.sync');
            Route::post('/php-errors/clear', [V1\LsmController::class, 'clearPhpErrors'])->name('php-errors.clear');
            
            // Activity Log (fetch from WordPress)
            Route::get('/activity', [V1\LsmController::class, 'getActivity'])->name('activity');
            Route::get('/activity/stats', [V1\LsmController::class, 'getActivityStats'])->name('activity.stats');
            
            // Site Info & Stats
            Route::get('/site-info', [V1\LsmController::class, 'getSiteInfo'])->name('site-info');
            Route::get('/users', [V1\LsmController::class, 'getUsers'])->name('users');
            
            // Security Settings
            Route::get('/security-settings', [V1\LsmController::class, 'getSecuritySettings'])->name('security-settings');
            Route::post('/security-settings', [V1\LsmController::class, 'updateSecuritySettings'])->name('security-settings.update');
            Route::get('/security-headers', [V1\LsmController::class, 'getSecurityHeaders'])->name('security-headers');
            Route::get('/security-headers/snippets', [V1\LsmController::class, 'getSecurityHeaderSnippets'])->name('security-headers.snippets');

            // Security Scanning
            Route::post('/security-scan', [V1\SecurityScanController::class, 'scan'])->name('security-scan');
            Route::get('/security-scans', [V1\SecurityScanController::class, 'index'])->name('security-scans.index');
            Route::get('/security-scans/latest', [V1\SecurityScanController::class, 'latest'])->name('security-scans.latest');
            Route::get('/security-scans/stats', [V1\SecurityScanController::class, 'stats'])->name('security-scans.stats');
            Route::get('/security-scans/{securityScan}', [V1\SecurityScanController::class, 'show'])->name('security-scans.show');
        });

        // -------------------------------------------------
        // CREDENTIALS (nested under projects)
        // -------------------------------------------------
        Route::apiResource('projects.credentials', V1\CredentialController::class)
            ->shallow()
            ->except(['show']);
        
        // Secure password reveal endpoint (requires explicit request)
        Route::post('/credentials/{credential}/reveal', [V1\CredentialController::class, 'reveal'])
            ->name('credentials.reveal');
            
        // Create share link
        Route::post('/credentials/{credential}/share', [V1\CredentialShareController::class, 'store'])
            ->name('credentials.share');

        // -------------------------------------------------
        // TODOS (nested under projects)
        // -------------------------------------------------
        Route::get('/my-todos', [V1\TodoController::class, 'myTasks'])->name('todos.my-tasks');
        Route::apiResource('projects.todos', V1\TodoController::class)->shallow();
        Route::get('/todos/{todo}/download', [V1\TodoController::class, 'download'])
            ->name('todos.download');

        // -------------------------------------------------
        // RESOURCES (nested under projects)
        // -------------------------------------------------
        Route::apiResource('projects.resources', V1\ResourceController::class)->shallow();
        Route::get('/resources/{resource}/download', [V1\ResourceController::class, 'download'])
            ->name('resources.download');

        // -------------------------------------------------
        // LIBRARY RESOURCES (Global shared files)
        // -------------------------------------------------
        Route::apiResource('library-resources', V1\LibraryResourceController::class);
        Route::get('/library-resources/{library_resource}/download', [V1\LibraryResourceController::class, 'download'])
            ->name('library-resources.download');
        Route::post('/library-resources/{library_resource}/link', [V1\LibraryResourceController::class, 'linkToProject'])
            ->name('library-resources.link');
        Route::post('/library-resources/{library_resource}/unlink', [V1\LibraryResourceController::class, 'unlinkFromProject'])
            ->name('library-resources.unlink');
        Route::get('/library-resources-categories', [V1\LibraryResourceController::class, 'categories'])
            ->name('library-resources.categories');

        // -------------------------------------------------
        // MAINTENANCE REPORTS (nested under projects)
        // -------------------------------------------------
        Route::apiResource('projects.maintenance-reports', V1\MaintenanceReportController::class)->shallow();
        Route::get('/maintenance-reports/{maintenance_report}/pdf', [V1\MaintenanceReportController::class, 'downloadPdf'])
            ->name('maintenance-reports.pdf');
        Route::get('/maintenance-reports/suggestions', [V1\MaintenanceReportController::class, 'suggestions'])
            ->name('maintenance-reports.suggestions');

        // -------------------------------------------------
        // BACKUPS (nested under projects)
        // -------------------------------------------------
        // Settings must come BEFORE apiResource to avoid conflict with shallow GET /backups/{backup}
        Route::get('/backups/settings', [V1\BackupController::class, 'settings'])
            ->name('backups.settings');
        Route::apiResource('projects.backups', V1\BackupController::class)->shallow();
        Route::get('/backups/{backup}/download', [V1\BackupController::class, 'download'])
            ->name('backups.download');
        Route::post('/backups/{backup}/restore', [V1\BackupController::class, 'restore'])
            ->name('backups.restore');
        Route::get('/projects/{project}/backups-stats', [V1\BackupController::class, 'stats'])
            ->name('projects.backups.stats');

        // -------------------------------------------------
        // PHP ERRORS (nested under projects)
        // -------------------------------------------------
        Route::apiResource('projects.php-errors', V1\PhpErrorController::class)
            ->shallow()
            ->except(['update']); // Errors are logged, not updated
        Route::post('/php-errors/{php_error}/resolve', [V1\PhpErrorController::class, 'resolve'])
            ->name('php-errors.resolve');
        Route::delete('/projects/{project}/php-errors', [V1\PhpErrorController::class, 'clear'])
            ->name('projects.php-errors.clear');
        Route::get('/projects/{project}/php-errors-stats', [V1\PhpErrorController::class, 'stats'])
            ->name('projects.php-errors.stats');


        // -------------------------------------------------
        // SUPPORT TICKETS
        // -------------------------------------------------
        Route::get('/support-tickets', [V1\SupportTicketController::class, 'indexAll'])
            ->name('support-tickets.index-all');
        Route::apiResource('projects.support-tickets', V1\SupportTicketController::class)->shallow();
        Route::post('/support-tickets/{support_ticket}/mark-read', [V1\SupportTicketController::class, 'markAsRead'])
            ->name('support-tickets.mark-read');
        Route::post('/support-tickets/{support_ticket}/create-todo', [V1\SupportTicketController::class, 'createTodo'])
            ->name('support-tickets.create-todo');
        Route::get('/projects/{project}/support-tickets/unread-count', [V1\SupportTicketController::class, 'unreadCount'])
            ->name('projects.support-tickets.unread-count');

        // -------------------------------------------------
        // VAULT (Global credentials view)
        // -------------------------------------------------
        Route::get('/vault', [V1\VaultController::class, 'index'])->name('vault.index');

        // -------------------------------------------------
        // TEAM AVAILABILITY
        // -------------------------------------------------
        Route::get('/availability', [V1\AvailabilityController::class, 'index'])->name('availability.index');
        Route::post('/availability', [V1\AvailabilityController::class, 'store'])->name('availability.store');

        // -------------------------------------------------
        // TEAM MANAGEMENT
        // -------------------------------------------------
        Route::middleware(['role:admin,manager'])->group(function () {
            Route::get('/team', [V1\TeamController::class, 'index'])->name('team.index');
            Route::get('/team/{user}', [V1\TeamController::class, 'show'])->name('team.show');
            Route::get('/team/{user}/projects', [V1\TeamController::class, 'projects'])->name('team.projects');
        });
        
        Route::middleware(['role:admin'])->group(function () {
            Route::post('/team', [V1\TeamController::class, 'store'])->name('team.store');
            Route::put('/team/{user}', [V1\TeamController::class, 'update'])->name('team.update');
            Route::delete('/team/{user}', [V1\TeamController::class, 'destroy'])->name('team.destroy');
            Route::post('/team/{user}/reset-password', [V1\TeamController::class, 'resetPassword'])->name('team.reset-password');
            Route::post('/team/{user}/send-reset-link', [V1\TeamController::class, 'sendPasswordResetLink'])->name('team.send-reset-link');
        });

        // -------------------------------------------------
        // ACTIVITY LOG (Admin only)
        // -------------------------------------------------
        Route::get('/activity', [V1\ActivityController::class, 'index'])
            ->middleware('role:admin')
            ->name('activity.index');

        // -------------------------------------------------
        // SEARCH (Rate limited)
        // -------------------------------------------------
        Route::get('/search', [V1\SearchController::class, 'search'])
            ->middleware('throttle:30,1')
            ->name('search');

        // -------------------------------------------------
        // TAGS
        // -------------------------------------------------
        Route::apiResource('tags', V1\TagController::class);

        // -------------------------------------------------
        // NOTIFICATIONS
        // -------------------------------------------------
        Route::get('/notifications', [V1\NotificationController::class, 'index'])->name('notifications.index');
        Route::post('/notifications/{id}/read', [V1\NotificationController::class, 'markAsRead'])->name('notifications.read');
        Route::post('/notifications/read-all', [V1\NotificationController::class, 'markAllAsRead'])->name('notifications.read-all');
        Route::get('/notifications/unread-count', [V1\NotificationController::class, 'unreadCount'])->name('notifications.unread-count');

        // -------------------------------------------------
        // EXPORT
        // -------------------------------------------------
        Route::prefix('export')->name('export.')->group(function () {
            Route::get('/projects', [V1\ExportController::class, 'projects'])->name('projects');
            Route::get('/projects/{project}', [V1\ExportController::class, 'project'])->name('project');
            Route::get('/credentials', [V1\ExportController::class, 'credentials'])->name('credentials');
        });

        // -------------------------------------------------
        // TIME TRACKING
        // -------------------------------------------------
        
        // Timer (quick actions)
        Route::prefix('timer')->name('timer.')->group(function () {
            Route::get('/current', [V1\TimerController::class, 'current'])->name('current');
            Route::post('/start', [V1\TimerController::class, 'start'])->name('start');
            Route::post('/stop', [V1\TimerController::class, 'stop'])->name('stop');
            Route::post('/discard', [V1\TimerController::class, 'discard'])->name('discard');
            Route::get('/projects', [V1\TimerController::class, 'projects'])->name('projects');
        });

        // Time Entries
        Route::apiResource('time-entries', V1\TimeEntryController::class);
        Route::get('/time-entries-today', [V1\TimeEntryController::class, 'today'])->name('time-entries.today');
        Route::post('/time-entries/submit', [V1\TimeEntryController::class, 'submitEntries'])->name('time-entries.submit');
        Route::post('/time-entries/approve', [V1\TimeEntryController::class, 'approveEntries'])->name('time-entries.approve');
        Route::post('/time-entries/reject', [V1\TimeEntryController::class, 'rejectEntries'])->name('time-entries.reject');

        // Timesheets
        Route::prefix('timesheets')->name('timesheets.')->group(function () {
            Route::get('/', [V1\TimesheetController::class, 'index'])->name('index');
            Route::get('/current', [V1\TimesheetController::class, 'current'])->name('current');
            Route::get('/by-week', [V1\TimesheetController::class, 'byWeek'])->name('by-week');
            Route::get('/pending', [V1\TimesheetController::class, 'pending'])->name('pending');
            Route::get('/{timesheet}', [V1\TimesheetController::class, 'show'])->name('show');
            Route::post('/{timesheet}/submit', [V1\TimesheetController::class, 'submit'])->name('submit');
            Route::post('/{timesheet}/approve', [V1\TimesheetController::class, 'approve'])->name('approve');
            Route::post('/{timesheet}/reject', [V1\TimesheetController::class, 'reject'])->name('reject');
        });

        // Invoices
        Route::prefix('invoices')->name('invoices.')->group(function () {
            Route::get('/', [V1\InvoiceController::class, 'index'])->name('index');
            Route::get('/pending', [V1\InvoiceController::class, 'pending'])->name('pending');
            Route::post('/from-timesheet', [V1\InvoiceController::class, 'createFromTimesheet'])->name('from-timesheet');
            Route::get('/{invoice}', [V1\InvoiceController::class, 'show'])->name('show');
            Route::post('/{invoice}/approve', [V1\InvoiceController::class, 'approve'])->name('approve');
            Route::post('/{invoice}/decline', [V1\InvoiceController::class, 'decline'])->name('decline');
            Route::post('/{invoice}/mark-paid', [V1\InvoiceController::class, 'markAsPaid'])->name('mark-paid');
            Route::get('/{invoice}/download-pdf', [V1\InvoiceController::class, 'downloadPdf'])->name('download-pdf');
        });
    });
});
