<?php

use App\Models\Project;
use App\Models\SecurityScan;
use App\Models\User;
use App\Notifications\MalwareDetectedNotification;

function renderMail($notification): string
{
    $user = User::factory()->make(['name' => 'Test User', 'email' => 'u@example.com']);

    return (string) $notification->toMail($user)->render();
}

it('renders the brand CTA color in notification emails', function () {
    $project = Project::factory()->make(['id' => 7, 'name' => 'Acme']);
    $scan = SecurityScan::factory()->make(['project_id' => 7, 'risk_level' => 'critical', 'threats_found' => 3]);

    $html = renderMail(new MalwareDetectedNotification($project, $scan));

    expect(strtolower($html))->toContain('#7c3aed');
});
