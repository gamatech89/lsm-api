<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-4-20250514'),
    ],

    'gdpr_audit' => [
        'url' => env('GDPR_AUDIT_SERVICE_URL'),
        'key' => env('GDPR_AUDIT_SERVICE_KEY', 'lsm-gdpr-audit-2026-secure-key'),
    ],

    'accessibility_audit' => [
        'url' => env('ACCESSIBILITY_AUDIT_SERVICE_URL'),
        'key' => env('ACCESSIBILITY_AUDIT_SERVICE_KEY', 'lsm-gdpr-audit-2026-secure-key'),
    ],

    'pdf_service' => [
        'url' => env('PDF_SERVICE_URL'),
        'key' => env('PDF_SERVICE_KEY', 'lsm-gdpr-audit-2026-secure-key'),
    ],

];
