<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCredentialRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('credential'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'type' => 'required|in:ssh,ftp,database,wordpress,hosting,email,api,other',
            'username' => 'nullable|string|max:255',
            'password' => 'nullable|string|max:1000',
            'url' => 'nullable|url|max:2000',
            'metadata' => 'nullable|array',
            'metadata.hostname' => 'nullable|string|max:255',
            'metadata.port' => 'nullable|string|max:10',
            'metadata.host' => 'nullable|string|max:255',
            'metadata.database_name' => 'nullable|string|max:255',
        ];
    }
}
