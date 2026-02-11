<?php

/**
 * Todo Templates Configuration
 * 
 * Define reusable sets of todos that can be applied to projects with a single command.
 * Each template has a name and an array of todos with title, priority, and optional description.
 */

return [
    'maintenance' => [
        'name' => 'Monthly Maintenance',
        'description' => 'Standard monthly maintenance checklist for WordPress sites',
        'todos' => [
            [
                'title' => 'Check if Wordfence is installed',
                'priority' => 'critical',
                'description' => 'Verify Wordfence security plugin is active and properly configured',
            ],
            [
                'title' => 'Check if our new theme is installed',
                'priority' => 'high',
                'description' => 'Ensure the latest theme version is installed',
            ],
            [
                'title' => 'Check if new plugin for forms is installed',
                'priority' => 'high',
                'description' => 'Verify form plugin is working correctly',
            ],
            [
                'title' => 'Check if database is clean',
                'priority' => 'critical',
                'description' => 'Review database for orphan data and optimize tables',
            ],
            [
                'title' => 'Check if malicious files on server/file system',
                'priority' => 'critical',
                'description' => 'Run malware scan on the file system',
            ],
        ],
    ],

    'security_audit' => [
        'name' => 'Security Audit',
        'description' => 'Comprehensive security review for compromised or at-risk sites',
        'todos' => [
            [
                'title' => 'Change all admin passwords',
                'priority' => 'urgent',
                'description' => 'Reset WordPress admin, FTP, and hosting passwords',
            ],
            [
                'title' => 'Review user accounts',
                'priority' => 'urgent',
                'description' => 'Remove suspicious users, verify legitimate accounts',
            ],
            [
                'title' => 'Scan for malware',
                'priority' => 'urgent',
                'description' => 'Run full malware scan with Wordfence or Sucuri',
            ],
            [
                'title' => 'Check file integrity',
                'priority' => 'high',
                'description' => 'Compare core files with original WordPress files',
            ],
            [
                'title' => 'Review recent file changes',
                'priority' => 'high',
                'description' => 'Check recently modified files for suspicious changes',
            ],
            [
                'title' => 'Update all plugins and themes',
                'priority' => 'high',
                'description' => 'Ensure all software is up to date',
            ],
        ],
    ],

    'onboarding' => [
        'name' => 'New Project Onboarding',
        'description' => 'Initial setup tasks for new WordPress projects',
        'todos' => [
            [
                'title' => 'Install maintenance plugin',
                'priority' => 'urgent',
                'description' => 'Install and configure Landeseiten Maintenance plugin',
            ],
            [
                'title' => 'Configure health monitoring',
                'priority' => 'high',
                'description' => 'Verify health checks are working',
            ],
            [
                'title' => 'Store credentials in vault',
                'priority' => 'high',
                'description' => 'Add WP admin, FTP, and hosting credentials',
            ],
            [
                'title' => 'Initial security review',
                'priority' => 'high',
                'description' => 'Check current security status and install Wordfence if needed',
            ],
            [
                'title' => 'Document site structure',
                'priority' => 'medium',
                'description' => 'Note important plugins, custom code, and special configurations',
            ],
        ],
    ],

    'performance' => [
        'name' => 'Performance Optimization',
        'description' => 'Speed and performance improvement tasks',
        'todos' => [
            [
                'title' => 'Install caching plugin',
                'priority' => 'high',
                'description' => 'Set up WP Super Cache or LiteSpeed Cache',
            ],
            [
                'title' => 'Optimize images',
                'priority' => 'medium',
                'description' => 'Compress existing images, set up Smush or ShortPixel',
            ],
            [
                'title' => 'Enable CDN',
                'priority' => 'medium',
                'description' => 'Configure Cloudflare or other CDN provider',
            ],
            [
                'title' => 'Minimize CSS/JS',
                'priority' => 'medium',
                'description' => 'Enable asset minification and combination',
            ],
        ],
    ],
];
