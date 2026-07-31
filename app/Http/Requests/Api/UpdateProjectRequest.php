<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Update Project Request
 * 
 * Validates data for updating a project via API.
 * All fields are optional for partial updates.
 */
class UpdateProjectRequest extends FormRequest
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
            $this->merge(['url' => StoreProjectRequest::normalizeUrl($this->url)]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array|string>
     */
    public function rules(): array
    {
        $routeProject = $this->route('project');
        $projectId = $routeProject instanceof \App\Models\Project
            ? $routeProject->id
            : (int) $routeProject;

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'url' => ['sometimes', 'url', 'max:255', function (string $attribute, mixed $value, \Closure $fail) use ($projectId) {
                // Normalize-aware duplicate check (excluding self)
                $normalizedNew = StoreProjectRequest::normalizeUrl($value);
                $query = \App\Models\Project::whereNotNull('url')
                    ->where('url', '!=', '');
                
                if ($projectId) {
                    $query->where('id', '!=', $projectId);
                }
                
                $existing = $query->pluck('url', 'id');
                
                foreach ($existing as $id => $dbUrl) {
                    if (StoreProjectRequest::normalizeUrl($dbUrl) === $normalizedNew) {
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
            
            // External identifiers — unique when provided (excluding self)
            'project_external_id' => ['nullable', 'string', 'max:50', Rule::unique('projects', 'project_external_id')->ignore($projectId)],
            'maintenance_id' => ['nullable', 'string', 'max:50', Rule::unique('projects', 'maintenance_id')->ignore($projectId)],
            
            // Hosting info
            'hosting_provider' => ['nullable', 'string', 'max:255'],
            'hosting_url' => ['nullable', 'url', 'max:255'],
            'ssh_access' => ['nullable', 'string', 'max:255'],
            
            // External links
            'drive_link' => ['nullable', 'url', 'max:255'],
            'trello_link' => ['nullable', 'url', 'max:255'],
            
            // WordPress integration
            'health_check_secret' => ['nullable', 'string', 'max:255'],
            
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
}
