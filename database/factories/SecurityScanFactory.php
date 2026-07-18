<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\SecurityScan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SecurityScan>
 */
class SecurityScanFactory extends Factory
{
    protected $model = SecurityScan::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'scan_type' => 'full',
            'status' => 'completed',
            'risk_level' => fake()->randomElement(['clean', 'low', 'medium', 'high', 'critical']),
            'threats_found' => fake()->numberBetween(0, 5),
            'warnings_found' => fake()->numberBetween(0, 10),
            'files_scanned' => fake()->numberBetween(10, 500),
            'duration_seconds' => fake()->randomFloat(2, 1, 120),
            'results' => null,
            'summary' => null,
            'triggered_by' => 'scheduled',
            'triggered_by_user_id' => null,
            'started_at' => now()->subMinutes(5),
            'completed_at' => now(),
        ];
    }

    /**
     * Indicate that the scan found critical threats.
     */
    public function critical(): static
    {
        return $this->state(fn (array $attributes) => [
            'risk_level' => 'critical',
            'threats_found' => fake()->numberBetween(1, 10),
        ]);
    }
}
