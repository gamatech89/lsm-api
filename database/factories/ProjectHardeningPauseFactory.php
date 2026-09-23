<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\ProjectHardeningPause;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProjectHardeningPauseFactory extends Factory
{
    protected $model = ProjectHardeningPause::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'user_id' => User::factory(),
            'paused_until' => now()->addHour(),
            'resumed_at' => null,
            'failed_at' => null,
            'overdue_notified_at' => null,
            'note' => null,
        ];
    }
}
