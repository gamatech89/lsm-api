<?php

namespace Database\Factories;

use App\Models\Credential;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Credential>
 */
class CredentialFactory extends Factory
{
    protected $model = Credential::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'title' => fake()->words(3, true),
            'type' => fake()->randomElement(['admin', 'hosting', 'database', 'api', 'ftp', 'other']),
            'username' => fake()->userName(),
            'password' => fake()->password(),
            'url' => fake()->url(),
            'metadata' => null,
        ];
    }

    /**
     * Indicate that the credential is an admin type.
     */
    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'admin',
        ]);
    }

    /**
     * Indicate that the credential is a hosting type.
     */
    public function hosting(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'hosting',
        ]);
    }

    /**
     * Indicate that the credential is a database type.
     */
    public function database(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'database',
        ]);
    }
}
