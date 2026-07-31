<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProjectRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', \App\Models\Project::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'url' => 'required|url',
            'domain' => 'nullable|string|max:255',
            'client_email' => 'nullable|email|max:255',
            'notes' => 'nullable|string|max:5000',
            'health_status' => 'required|in:online,down_error,updating',
            'security_status' => 'required|in:secure,monitoring,compromised,hacked',
            'manager_id' => ['nullable', Rule::exists('users', 'id')->whereIn('role', ['admin', 'manager'])],
            'developer_id' => 'nullable|exists:users,id',
            'project_external_id' => ['nullable', 'string', 'unique:projects,project_external_id', 'regex:/^LP\d{5}$/'],
            'hosting_provider' => 'nullable|string|max:255',
            'hosting_url' => 'nullable|string|max:2000',
            'ssh_access' => 'nullable|string|max:255',
            'drive_link' => 'nullable|url|max:2000',
            'trello_link' => 'nullable|url|max:2000',
        ];
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
