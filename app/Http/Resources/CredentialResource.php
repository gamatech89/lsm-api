<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Credential Resource
 * 
 * Transforms Credential model for API responses.
 * 
 * SECURITY: Password is NEVER returned by default.
 * Use the dedicated /credentials/{id}/reveal endpoint to get the password.
 */
class CredentialResource extends JsonResource
{
    /**
     * Indicates if the password should be revealed.
     * Set via the static method withPassword()
     */
    private bool $revealPassword = false;

    /**
     * Create a new resource that includes the password.
     * Use only for the reveal endpoint with proper authorization.
     */
    public static function withPassword($resource): self
    {
        $instance = new static($resource);
        $instance->revealPassword = true;
        return $instance;
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'title' => $this->title,
            'type' => $this->type,
            'username' => $this->username,
            'url' => $this->url,
            'note' => $this->note,
            'metadata' => $this->metadata,
            
            // Password is ONLY included when explicitly requested via withPassword()
            // or when accessing the reveal endpoint
            'password' => $this->when(
                $this->revealPassword || $request->routeIs('api.v1.credentials.reveal'),
                fn() => $this->getDecryptedPassword()
            ),
            
            // Indicate whether password exists (without revealing it)
            'has_password' => !empty($this->password),
            
            // Project reference (conditionally loaded)
            'project' => new ProjectResource($this->whenLoaded('project')),
            
            // Timestamps
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }

    /**
     * Get the decrypted password.
     * This method handles the Laravel Crypt facade decryption.
     */
    private function getDecryptedPassword(): ?string
    {
        if (empty($this->resource->password)) {
            return null;
        }

        try {
            // The model already has a getDecryptedPasswordAttribute accessor
            // or we decrypt it here
            if (method_exists($this->resource, 'getDecryptedPasswordAttribute')) {
                return $this->resource->decrypted_password;
            }
            
            return \Illuminate\Support\Facades\Crypt::decryptString($this->resource->password);
        } catch (\Exception $e) {
            return null;
        }
    }
}
