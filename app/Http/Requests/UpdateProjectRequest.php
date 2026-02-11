<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProjectRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('project'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $project = $this->route('project');
        
        $rules = [
            'name' => 'required|string|max:255',
            'url' => 'required|url',
            'domain' => 'nullable|string|max:255',
            'client_email' => 'nullable|email|max:255',
            'notes' => 'nullable|string|max:5000',
            'health_status' => 'required|in:online,down_error,updating',
            'security_status' => 'required|in:secure,monitoring,compromised,hacked',
            'project_external_id' => ['nullable', 'string', 'unique:projects,project_external_id,' . $project->id, 'regex:/^LP\d{5}$/'],
            'hosting_provider' => 'nullable|string|max:255',
            'hosting_url' => 'nullable|string|max:2000',
            'ssh_access' => 'nullable|string|max:255',
            'drive_link' => 'nullable|url|max:2000',
            'trello_link' => 'nullable|url|max:2000',
        ];

        // Only admins can change the manager
        if ($this->user()->isAdmin()) {
            $rules['manager_id'] = 'nullable|exists:users,id';
        }

        // Admins and managers can change the developer
        if ($this->user()->isAdmin() || $this->user()->isManager()) {
            $rules['developer_id'] = 'nullable|exists:users,id';
        }

        return $rules;
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'project_external_id.regex' => 'External ID must be in format LP followed by 5 digits (e.g., LP10001)',
        ];
    }
}
