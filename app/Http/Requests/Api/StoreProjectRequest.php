<?php

namespace App\Http\Requests\Api;

use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Store Project Request
 * 
 * Validates data for creating a new project via API.
 */
class StoreProjectRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Authorization is handled by controller/policy
        return true;
    }

    /**
     * Prepare the data for validation (normalize URL).
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('url')) {
            $this->merge(['url' => self::normalizeUrl($this->url)]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'url' => ['required', 'url', 'max:255', function (string $attribute, mixed $value, \Closure $fail) {
                // Normalize-aware duplicate check: compare normalized incoming URL
                // against normalized DB URLs (handles www., trailing slash, case differences)
                $normalizedNew = self::normalizeUrl($value);
                $existing = Project::whereNotNull('url')
                    ->where('url', '!=', '')
                    ->pluck('url', 'id');
                
                foreach ($existing as $id => $dbUrl) {
                    if (self::normalizeUrl($dbUrl) === $normalizedNew) {
                        $fail('A project with this URL already exists.');
                        return;
                    }
                }
            }],
            'domain' => ['nullable', 'string', 'max:255'],
            'client_email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string'],
            
            // Status fields
            'health_status' => ['sometimes', 'string', 'in:online,down_error,updating'],
            'security_status' => ['sometimes', 'string', 'in:secure,monitoring,compromised,hacked'],
            
            // External identifiers — unique when provided
            'project_external_id' => ['nullable', 'string', 'max:50', 'unique:projects,project_external_id'],
            'maintenance_id' => ['nullable', 'string', 'max:50', 'unique:projects,maintenance_id'],
            
            // Hosting info
            'hosting_provider' => ['nullable', 'string', 'max:255'],
            'hosting_url' => ['nullable', 'url', 'max:255'],
            'ssh_access' => ['nullable', 'string', 'max:255'],
            
            // External links
            'drive_link' => ['nullable', 'url', 'max:255'],
            'trello_link' => ['nullable', 'url', 'max:255'],
            
            // Assignments
            'manager_id' => ['nullable', Rule::exists('users', 'id')->whereIn('role', ['admin', 'manager'])],
            'manager_ids' => ['nullable', 'array'],
            'manager_ids.*' => [Rule::exists('users', 'id')->whereIn('role', ['admin', 'manager'])],
            'developer_ids' => ['nullable', 'array'],
            'developer_ids.*' => ['exists:users,id'],
            'tag_ids' => ['nullable', 'array'],
            'tag_ids.*' => ['exists:tags,id'],
        ];
    }

    /**
     * Custom validation messages.
     */
    public function messages(): array
    {
        return [
            'url.unique' => 'A project with this URL already exists.',
            'project_external_id.unique' => 'A project with this External ID already exists.',
            'maintenance_id.unique' => 'A project with this Maintenance ID already exists.',
        ];
    }

    /**
     * Normalize a URL for consistent duplicate detection.
     * Strips www., trailing slashes, forces lowercase host, removes default ports.
     */
    public static function normalizeUrl(?string $url): ?string
    {
        if (empty($url)) return $url;

        $url = trim($url);
        $parsed = parse_url($url);
        if (!$parsed || empty($parsed['host'])) return $url;

        $scheme = strtolower($parsed['scheme'] ?? 'https');
        $host = strtolower($parsed['host']);

        // Strip www.
        $host = preg_replace('/^www\./', '', $host);

        // Remove default ports
        $port = $parsed['port'] ?? null;
        if (($scheme === 'http' && $port == 80) || ($scheme === 'https' && $port == 443)) {
            $port = null;
        }

        // Rebuild
        $normalized = $scheme . '://' . $host;
        if ($port) {
            $normalized .= ':' . $port;
        }

        $path = $parsed['path'] ?? '/';
        // Remove trailing slash (except for root)
        $path = rtrim($path, '/');
        if (empty($path)) $path = '';

        $normalized .= $path;

        return $normalized;
    }
}
