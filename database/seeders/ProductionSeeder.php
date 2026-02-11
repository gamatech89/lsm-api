<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Project;
use App\Models\Resource;
use App\Models\Todo;
use App\Models\Tag;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class ProductionSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database with production-like data.
     */
    public function run(): void
    {
        $this->command->info('🗑️  Clearing all existing data...');
        $this->clearAllData();

        $this->command->info('👥 Creating users...');
        $users = $this->createUsers();

        $this->command->info('📁 Importing projects from CSV...');
        $this->importProjectsFromCsv($users);

        $this->command->info('✅ Seeding complete!');
    }

    /**
     * Clear all existing data from the database.
     */
    private function clearAllData(): void
    {
        $driver = DB::getDriverName();
        
        // Disable foreign key checks temporarily
        if ($driver === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = OFF;');
        } else {
            DB::statement('SET FOREIGN_KEY_CHECKS = 0;');
        }

        // Clear all tables in correct order
        DB::table('credential_share_access_logs')->truncate();
        DB::table('credential_share_links')->truncate();
        DB::table('credentials')->truncate();
        DB::table('resources')->truncate();
        DB::table('todos')->truncate();
        DB::table('project_tag')->truncate();
        DB::table('project_developer')->truncate();
        DB::table('tags')->truncate();
        DB::table('projects')->truncate();
        DB::table('notifications')->truncate();
        DB::table('activity_log')->truncate();
        DB::table('users')->truncate();

        // Re-enable foreign key checks
        if ($driver === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = ON;');
        } else {
            DB::statement('SET FOREIGN_KEY_CHECKS = 1;');
        }
    }

    /**
     * Create user accounts.
     * 
     * Passwords are read from environment variable SEED_PASSWORD.
     * Set this in .env before running: SEED_PASSWORD=your_secure_password
     */
    private function createUsers(): array
    {
        // Get password from environment - MUST be set before seeding
        $seedPassword = env('SEED_PASSWORD');
        
        if (empty($seedPassword)) {
            throw new \RuntimeException(
                'SEED_PASSWORD environment variable is not set. ' .
                'Add SEED_PASSWORD=your_secure_password to your .env file before running seeders.'
            );
        }

        $hashedPassword = Hash::make($seedPassword);

        // Admin
        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@landeseiten.de',
            'password' => $hashedPassword,
            'role' => 'admin',
        ]);

        // Project Managers
        $vinzent = User::create([
            'name' => 'Vinzent',
            'email' => 'vinzent@landeseiten.de',
            'password' => $hashedPassword,
            'role' => 'manager',
        ]);

        $daniel = User::create([
            'name' => 'Daniel',
            'email' => 'daniel@landeseiten.de',
            'password' => $hashedPassword,
            'role' => 'manager',
        ]);

        $yannick = User::create([
            'name' => 'Yannick',
            'email' => 'yannick@landeseiten.de',
            'password' => $hashedPassword,
            'role' => 'manager',
        ]);

        // Developers
        $bojan = User::create([
            'name' => 'Bojan',
            'email' => 'bojan@landeseiten.de',
            'password' => $hashedPassword,
            'role' => 'developer',
        ]);

        $miroslav = User::create([
            'name' => 'Miroslav',
            'email' => 'miroslav@landeseiten.de',
            'password' => $hashedPassword,
            'role' => 'developer',
        ]);

        return [
            'admin' => $admin,
            'vinzent' => $vinzent,
            'daniel' => $daniel,
            'yannick' => $yannick,
            'bojan' => $bojan,
            'miroslav' => $miroslav,
        ];
    }

    /**
     * Import projects from CSV file.
     */
    private function importProjectsFromCsv(array $users): void
    {
        $csvPath = database_path('data/projects.csv');
        
        if (!file_exists($csvPath)) {
            $this->command->error("CSV file not found: {$csvPath}");
            return;
        }

        $handle = fopen($csvPath, 'r');
        
        // Skip header row
        $headers = fgetcsv($handle);
        
        $projectCount = 0;
        $todoCount = 0;
        $seenProjects = []; // Track unique projects by name to avoid duplicates

        while (($row = fgetcsv($handle)) !== false) {
            // Map CSV columns
            // 0: project_external_id (LP/WV prefix)
            // 1: name
            // 2: domain
            // 3: hacked (Ja = compromised)
            // 4: pm
            // 5: developer
            // 6: hosting_provider
            // 7: hosting_url
            // 8: username
            // 9: password
            // 10: ssh_access
            // 11: drive_link
            // 12: trello_link
            // 13: notes

            $externalId = trim($row[0] ?? '');
            $rawName = trim($row[1] ?? '');
            $domain = trim($row[2] ?? '');
            $hacked = trim($row[3] ?? '');
            $pmName = trim($row[4] ?? '');
            $developerName = trim($row[5] ?? '');
            $hostingProvider = trim($row[6] ?? '');
            $hostingUrl = trim($row[7] ?? '');
            $username = trim($row[8] ?? '');
            $password = trim($row[9] ?? '');
            $sshAccess = trim($row[10] ?? '');
            $driveLink = trim($row[11] ?? '');
            $trelloLink = trim($row[12] ?? '');
            $notes = trim($row[13] ?? '');

            // Skip if no name
            if (empty($rawName)) {
                continue;
            }

            // Clean project name - remove WV prefix patterns like "WV10148_"
            $projectName = $this->cleanProjectName($rawName);

            // Skip duplicates based on cleaned name
            if (isset($seenProjects[$projectName])) {
                continue;
            }
            $seenProjects[$projectName] = true;

            // Extract maintenance_id (WV number) from external_id or name
            $maintenanceId = null;
            if (preg_match('/^(WV\d+)/i', $externalId, $matches)) {
                $maintenanceId = $matches[1];
            } elseif (preg_match('/^(WV\d+)/i', $rawName, $matches)) {
                $maintenanceId = $matches[1];
            }

            // Extract project_external_id (LP number)
            $projectExternalId = null;
            if (preg_match('/^(LP\d+)/i', $externalId, $matches)) {
                $projectExternalId = $matches[1];
            }

            // Clean and normalize URL
            $url = $this->normalizeUrl($domain);

            // Determine security status
            $securityStatus = (strtolower($hacked) === 'ja') ? 'compromised' : 'secure';

            // Map PM name to user
            $managerId = $this->findUserIdByName($pmName, $users);
            
            // Map developer name - for now assign to Bojan or Miroslav randomly if developer specified
            $developerId = null;
            if (!empty($developerName)) {
                // Randomly assign to one of our two developers
                $developerId = rand(0, 1) === 0 ? $users['bojan']->id : $users['miroslav']->id;
            }

            // Clean hosting provider
            $hostingProvider = $this->cleanHostingProvider($hostingProvider);

            // Create project
            $project = Project::create([
                'name' => $projectName,
                'url' => $url,
                'domain' => $this->extractDomain($url),
                'project_external_id' => $projectExternalId,
                'maintenance_id' => $maintenanceId,
                'hosting_provider' => $hostingProvider,
                'hosting_url' => $this->isValidUrl($hostingUrl) ? $hostingUrl : null,
                'ssh_access' => !empty($sshAccess) ? $sshAccess : null,
                'drive_link' => $this->isValidUrl($driveLink) ? $driveLink : null,
                'trello_link' => $this->isValidUrl($trelloLink) ? $trelloLink : null,
                'health_status' => 'online', // Valid: online, down_error, updating
                'security_status' => $securityStatus, // Valid: secure, monitoring, compromised, hacked
                'manager_id' => $managerId,
                'developer_id' => $developerId,
                'notes' => !empty($notes) && $notes !== 'Ja' && $notes !== 'Nein' ? $notes : null,
            ]);

            $projectCount++;

            // Create standard maintenance todos for each project
            $maintenanceTodos = [
                ['title' => 'Check if Wordfence is installed', 'priority' => 'critical'],
                ['title' => 'Check if our new theme is installed', 'priority' => 'high'],
                ['title' => 'Check if new plugin for forms is installed', 'priority' => 'high'],
                ['title' => 'Check if database is clean', 'priority' => 'critical'],
                ['title' => 'Check if malicious files on server/file system', 'priority' => 'critical'],
            ];

            foreach ($maintenanceTodos as $todo) {
                Todo::create([
                    'project_id' => $project->id,
                    'title' => $todo['title'],
                    'priority' => $todo['priority'],
                    'status' => 'pending',
                ]);
                $todoCount++;
            }
        }

        fclose($handle);

        $this->command->info("   Created {$projectCount} projects");
        $this->command->info("   Created {$todoCount} todos");
    }

    /**
     * Clean project name by removing WV prefix patterns.
     */
    private function cleanProjectName(string $name): string
    {
        // Remove patterns like "WV10148_" from start of name
        $cleaned = preg_replace('/^WV\d+_\s*/i', '', $name);
        
        // Also handle patterns with underscore or space separator
        $cleaned = preg_replace('/^WV\d+\s+/i', '', $cleaned);
        
        // Remove any leading/trailing whitespace or quotes
        $cleaned = trim($cleaned, " \t\n\r\0\x0B\"'");
        
        return $cleaned;
    }

    /**
     * Normalize URL - add https:// and remove www.
     */
    private function normalizeUrl(?string $url): ?string
    {
        if (empty($url)) {
            return null;
        }

        $url = trim($url);

        // Skip non-URL values
        if (in_array(strtolower($url), ['k.a.', 'login page broken', 'cronjobs errors', 'neu - fragen', 'ggfs. neu?', 'muss neu erstellt werden'])) {
            return null;
        }

        // Remove www.
        $url = preg_replace('/^(https?:\/\/)?www\./i', '$1', $url);
        
        // Add https:// if no protocol
        if (!preg_match('/^https?:\/\//i', $url)) {
            $url = 'https://' . $url;
        }

        // Convert http to https
        $url = preg_replace('/^http:\/\//i', 'https://', $url);

        return $url;
    }

    /**
     * Extract domain from URL.
     */
    private function extractDomain(?string $url): ?string
    {
        if (empty($url)) {
            return null;
        }

        $parsed = parse_url($url);
        return $parsed['host'] ?? null;
    }

    /**
     * Check if string is a valid URL.
     */
    private function isValidUrl(?string $url): bool
    {
        if (empty($url)) {
            return false;
        }

        // Add protocol if missing for validation
        if (!preg_match('/^https?:\/\//i', $url)) {
            $url = 'https://' . $url;
        }

        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * Find user ID by name.
     */
    private function findUserIdByName(string $name, array $users): ?int
    {
        $name = strtolower(trim($name));
        
        $mapping = [
            'vinzent' => $users['vinzent']->id,
            'daniel' => $users['daniel']->id,
            'yannick' => $users['yannick']->id,
        ];

        return $mapping[$name] ?? null;
    }

    /**
     * Clean hosting provider name.
     */
    private function cleanHostingProvider(?string $provider): ?string
    {
        if (empty($provider)) {
            return null;
        }

        $provider = trim($provider);

        // Skip if it's a URL or non-provider value
        if (str_starts_with($provider, 'http') || str_starts_with($provider, 'FTP')) {
            return null;
        }

        // Normalize common provider names
        $normalized = strtolower($provider);
        
        $providerMapping = [
            'raidboxes' => 'Raidboxes',
            'ionos' => 'IONOS',
            'ions' => 'IONOS',
            '1blu' => '1blu',
            'strato' => 'Strato',
            'allinkl' => 'ALL-INKL',
            'all-inkl' => 'ALL-INKL',
            'all-inkl.com' => 'ALL-INKL',
            'mittwald' => 'Mittwald',
            'checkdomain' => 'Checkdomain',
            'metanet' => 'Metanet',
            'metanet.ch' => 'Metanet',
            'novatrend' => 'NovaTrend',
            'telekom' => 'Telekom',
        ];

        foreach ($providerMapping as $key => $value) {
            if (str_contains($normalized, $key)) {
                return $value;
            }
        }

        return $provider;
    }
}
