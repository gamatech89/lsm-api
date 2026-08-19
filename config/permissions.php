<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Manager Project Visibility
    |--------------------------------------------------------------------------
    |
    | When true (the default), project managers can SEE every project — the
    | project list, project detail, dashboard stats, search and the MCP
    | project reads — regardless of assignment. Write authority is not
    | affected: updating, deleting, credentials and team assignment still
    | require managing the project (ProjectPolicy / Project::isManagedBy()).
    |
    | This is a deliberate "for now" switch (Aug 2026): the team wants PMs to
    | have full visibility until proper per-client scoping is designed. Set
    | MANAGERS_VIEW_ALL_PROJECTS=false (+ config:cache) to restore
    | assignment-scoped visibility without a code change.
    |
    */

    'managers_view_all_projects' => filter_var(env('MANAGERS_VIEW_ALL_PROJECTS', true), FILTER_VALIDATE_BOOLEAN),

];
