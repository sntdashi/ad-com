<?php

namespace App\Http\Requests\Settings;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAssistantSettingsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return in_array($this->user()?->role, ['admin', 'operator'], true);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'model' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'api_key' => ['nullable', 'string', 'max:4000'],
            'clear_api_key' => ['sometimes', 'boolean'],
            'is_enabled' => ['sometimes', 'boolean'],
            'base_url' => ['nullable', 'url:http,https', 'max:255'],
            'max_output_tokens' => ['nullable', 'integer', 'min:150', 'max:2000'],
            'custom_instructions' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
