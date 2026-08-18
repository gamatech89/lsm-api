<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Backup Feature Switch
    |--------------------------------------------------------------------------
    |
    | Master switch for the whole backup feature. OFF by default: the feature
    | is not in use yet and a failed create attempt leaves an orphaned multi-GB
    | zip on the client site. When false, every backup API route except
    | GET /backups/settings answers 403, the MCP backup tools are not
    | registered, the SPA hides the backup UI, and no backup scheduler task is
    | registered. Set BACKUP_ENABLED=true (and run config:cache) to turn it on.
    |
    */

    'enabled' => env('BACKUP_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Default Backup Storage Driver
    |--------------------------------------------------------------------------
    |
    | This option controls the default backup storage driver that will be used
    | to store backup files. You can set this to any of the configured disks
    | in config/filesystems.php.
    |
    | Supported: "local", "s3", "gcs", "gdrive"
    |
    */

    'driver' => env('BACKUP_DRIVER', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Disk Mapping
    |--------------------------------------------------------------------------
    |
    | Maps the driver names to the actual filesystem disk names defined in
    | config/filesystems.php
    |
    */

    'disks' => [
        'local' => 'backups',
        's3' => 'backups-s3',
        'gcs' => 'backups-gcs',
        'gdrive' => 'backups-gdrive',
    ],

    /*
    |--------------------------------------------------------------------------
    | Backup Retention
    |--------------------------------------------------------------------------
    |
    | These settings control how many backups to keep and for how long.
    |
    */

    'retention' => [
        // Maximum number of backups to keep per project
        'max_backups' => env('BACKUP_MAX_COUNT', 10),

        // Maximum age of backups in days (0 = no limit)
        'max_age_days' => env('BACKUP_MAX_AGE_DAYS', 30),

        // Keep at least this many backups regardless of age
        'min_backups' => env('BACKUP_MIN_COUNT', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Backup Scheduling
    |--------------------------------------------------------------------------
    |
    | Configure automatic backup scheduling.
    |
    */

    'schedule' => [
        // Enable automatic scheduled backups.
        //
        // OFF by default: backups must only be created on explicit request
        // (SPA "Create Backup" button, POST /projects/{id}/backups, MCP tool).
        // When this is false the ScheduledBackupJob is not even registered
        // with the scheduler (see routes/console.php). Set
        // BACKUP_SCHEDULE_ENABLED=true to opt in to nightly automatic backups.
        'enabled' => env('BACKUP_SCHEDULE_ENABLED', false),

        // Frequency: daily, weekly, monthly
        'frequency' => env('BACKUP_SCHEDULE_FREQUENCY', 'weekly'),

        // Time to run (24-hour format, in server timezone)
        'time' => env('BACKUP_SCHEDULE_TIME', '03:00'),

        // Day of week for weekly backups (0 = Sunday, 6 = Saturday)
        'day_of_week' => env('BACKUP_SCHEDULE_DAY', 0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Backup Contents
    |--------------------------------------------------------------------------
    |
    | Default settings for what to include in backups.
    |
    */

    'defaults' => [
        'includes_database' => true,
        'includes_files' => true,
        'includes_uploads' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    |
    | Configure backup notifications.
    |
    */

    'notifications' => [
        // Notify on successful backup
        'on_success' => env('BACKUP_NOTIFY_SUCCESS', false),

        // Notify on failed backup
        'on_failure' => env('BACKUP_NOTIFY_FAILURE', true),

        // Notification channels (mail, slack, etc)
        'channels' => ['mail'],
    ],

];
