<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;

class EncryptExistingCredentials extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'credentials:encrypt';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Encrypt existing plaintext usernames in credentials table';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Encrypting existing credential usernames...');
        
        // Get all credentials with raw query to avoid model casting
        $credentials = DB::table('credentials')->get();
        
        $count = 0;
        foreach ($credentials as $credential) {
            // Skip if username is null or empty
            if (empty($credential->username)) {
                continue;
            }
            
            // Check if it's already encrypted (encrypted values are base64 JSON)
            $isEncrypted = $this->isEncrypted($credential->username);
            
            if (!$isEncrypted) {
                // Encrypt the plaintext username
                $encryptedUsername = Crypt::encryptString($credential->username);
                
                DB::table('credentials')
                    ->where('id', $credential->id)
                    ->update(['username' => $encryptedUsername]);
                
                $count++;
                $this->line("  Encrypted username for credential ID: {$credential->id}");
            }
        }
        
        $this->info("Done! Encrypted {$count} credential usernames.");
        
        return 0;
    }
    
    /**
     * Check if a string appears to be encrypted.
     */
    private function isEncrypted(string $value): bool
    {
        // Laravel encrypted values are base64 encoded JSON with specific structure
        $decoded = base64_decode($value, true);
        if ($decoded === false) {
            return false;
        }
        
        $json = json_decode($decoded, true);
        return is_array($json) && isset($json['iv']) && isset($json['value']);
    }
}
