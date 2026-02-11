<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Project>
 */
class ProjectFactory extends Factory
{
    protected $model = Project::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'url' => fake()->url(),
            'domain' => fake()->domainName(),
            'client_email' => fake()->email(),
            'notes' => fake()->paragraph(),
            'health_status' => fake()->randomElement(['online', 'down_error', 'updating']),
            'security_status' => fake()->randomElement(['secure', 'monitoring', 'compromised', 'hacked']),
            'project_external_id' => 'LP' . fake()->unique()->numerify('#####'),
            'hosting_provider' => fake()->randomElement(['Strato', 'IONOS', 'Hetzner', 'AWS']),
            'hosting_url' => fake()->url(),
            'manager_id' => null,
            'developer_id' => null,
        ];
    }

    /**
     * Indicate that the project is online and secure.
     */
    public function healthy(): static
    {
        return $this->state(fn (array $attributes) => [
            'health_status' => 'online',
            'security_status' => 'secure',
        ]);
    }

    /**
     * Indicate that the project has issues.
     */
    public function problematic(): static
    {
        return $this->state(fn (array $attributes) => [
            'health_status' => 'down_error',
            'security_status' => fake()->randomElement(['compromised', 'hacked']),
        ]);
    }

    /**
     * Assign a manager to the project.
     */
    public function withManager(User $user = null): static
    {
        return $this->state(fn (array $attributes) => [
            'manager_id' => $user?->id ?? User::factory()->create(['role' => 'manager'])->id,
        ]);
    }

    /**
     * Assign a developer to the project.
     */
    public function withDeveloper(User $user = null): static
    {
        return $this->state(fn (array $attributes) => [
            'developer_id' => $user?->id ?? User::factory()->create(['role' => 'developer'])->id,
        ]);
    }
}
