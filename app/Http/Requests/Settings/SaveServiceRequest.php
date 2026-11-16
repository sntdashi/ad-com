<?php

namespace App\Http\Requests\Settings;

use App\Models\Service;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor !== null
            && $actor->is_active
            && $actor->role === 'operator';
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Service|null $service */
        $service = $this->route('service');

        return [
            'code' => [
                'required',
                'string',
                'max:16',
                'regex:/^[A-Za-z0-9._-]+$/',
                Rule::unique('services', 'code')->ignore($service?->id),
            ],
            'name' => ['required', 'string', 'max:120'],
            'summary' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'starting_price' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'pricing_label' => ['required', 'string', 'max:120'],
            'service_mode' => ['required', Rule::in(['onsite', 'dropoff', 'remote'])],
            'risk_level' => ['required', Rule::in(['low', 'medium', 'high'])],
            'warranty_days' => ['required', 'integer', 'min:0', 'max:365'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:999'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
