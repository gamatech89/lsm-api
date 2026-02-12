<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

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
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'url' => ['required', 'url', 'max:255'],
            'domain' => ['nullable', 'string', 'max:255'],
            'client_email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string'],
            
            // Status fields
            'health_status' => ['sometimes', 'string', 'in:online,down_error,updating'],
            'security_status' => ['sometimes', 'string', 'in:secure,monitoring,compromised,hacked'],
            
            // External identifiers
            'project_external_id' => ['nullable', 'string', 'max:50'],
            'maintenance_id' => ['nullable', 'string', 'max:50'],
            
            // Hosting info
            'hosting_provider' => ['nullable', 'string', 'max:255'],
            'hosting_url' => ['nullable', 'url', 'max:255'],
            'ssh_access' => ['nullable', 'string', 'max:255'],
            
            // External links
            'drive_link' => ['nullable', 'url', 'max:255'],
            'trello_link' => ['nullable', 'url', 'max:255'],
            
            // Assignments
            'manager_id' => ['nullable', 'exists:users,id'],
            'manager_ids' => ['nullable', 'array'],
            'manager_ids.*' => ['exists:users,id'],
            'developer_ids' => ['nullable', 'array'],
            'developer_ids.*' => ['exists:users,id'],
            'tag_ids' => ['nullable', 'array'],
            'tag_ids.*' => ['exists:tags,id'],
        ];
    }
}
