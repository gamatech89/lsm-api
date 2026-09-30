<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Plugin Ticket Intake Switch
    |--------------------------------------------------------------------------
    |
    | Master switch for tickets SENT BY THE WORDPRESS PLUGIN: the legacy
    | webhook (POST /webhooks/support-ticket) and the plugin ticketing API
    | (POST /plugin/support-tickets, POST /plugin/support-tickets/{id}/messages).
    | When false those three routes answer 503 "temporarily disabled" and
    | nothing is stored; reading existing tickets from the plugin keeps working,
    | and the platform's own ticket UI is unaffected. Set
    | PLUGIN_TICKETING_ENABLED=false (and run config:cache) to switch intake off.
    |
    */

    // filter_var so PLUGIN_TICKETING_ENABLED=off|no|0 also reads as false.
    'plugin_intake_enabled' => filter_var(env('PLUGIN_TICKETING_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

];
