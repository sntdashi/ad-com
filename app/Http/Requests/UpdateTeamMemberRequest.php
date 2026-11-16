<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTeamMemberRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $actor = $this->user();
        $member = $this->route('user');

        if (! $actor || ! $member instanceof User || $actor->id === $member->id) {
            return false;
        }

        if ($actor->role === 'operator') {
            return true;
        }

        return $actor->role === 'admin' && $member->role !== 'operator';
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $roles = $this->user()?->role === 'operator'
            ? ['operator', 'admin', 'supervisor', 'technician', 'warehouse', 'customer']
            : ['admin', 'supervisor', 'technician', 'warehouse', 'customer'];

        return [
            'role' => ['required', Rule::in($roles)],
            'phone' => ['nullable', 'string', 'max:24'],
            'avatar_url' => ['nullable', 'url:http,https', 'max:2048'],
            'specialty' => ['nullable', 'string', 'max:255'],
            'bio' => ['nullable', 'string', 'max:1000'],
            'show_phone_publicly' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
