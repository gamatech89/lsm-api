<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class RefillUsersSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Remove all existing Developers and Managers
        // We use forceDelete() to actually remove them, or delete() for soft delete.
        // Given "clean developer", forceDelete is probably cleaner to avoid unique email conflicts if we re-add same names.
        User::whereIn('role', ['developer', 'manager'])->forceDelete();

        $defaultPassword = Hash::make('password'); // Simple default password

        // 2. Create new Developers
        $developers = ['Bojan', 'Stefan', 'Miroslav', 'Amir', 'Vlad'];
        
        foreach ($developers as $name) {
            User::create([
                'name' => $name,
                'email' => strtolower($name) . '@example.com',
                'password' => $defaultPassword,
                'role' => 'developer',
                'hourly_rate' => 25.00, // Default rate
                'email_verified_at' => now(),
            ]);
        }

        // 3. Create new Managers (PMs)
        $managers = ['Daniel', 'Lisa', 'Yannick', 'Susanne', 'Laura', 'Jonas'];

        foreach ($managers as $name) {
            User::create([
                'name' => $name,
                'email' => strtolower($name) . '@example.com',
                'password' => $defaultPassword,
                'role' => 'manager',
                'email_verified_at' => now(),
            ]);
        }
        
        $this->command->info('Users refreshed successfully!');
        $this->command->info('Created ' . count($developers) . ' developers and ' . count($managers) . ' managers.');
    }
}
