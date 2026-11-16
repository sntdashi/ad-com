<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreServiceOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $phone = preg_replace('/\D+/', '', (string) $this->input('phone'));

        if (str_starts_with($phone, '0')) {
            $phone = '62'.substr($phone, 1);
        }

        $this->merge([
            'phone' => $phone,
            'email' => $this->filled('email') ? strtolower(trim((string) $this->input('email'))) : null,
        ]);
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'service_id' => [
                'required',
                Rule::exists('services', 'id')->where('is_active', true),
            ],
            'customer_name' => ['required', 'string', 'min:2', 'max:100'],
            'phone' => ['required', 'regex:/^62[0-9]{8,13}$/'],
            'email' => ['nullable', 'email:rfc', 'max:160'],
            'address' => ['required', 'string', 'min:10', 'max:1000'],
            'district' => ['nullable', 'string', 'max:100'],
            'service_mode' => ['required', Rule::in(['onsite', 'dropoff', 'remote'])],
            'complaint' => ['required', 'string', 'min:20', 'max:3000'],
            'preferred_date' => ['nullable', 'date', 'after_or_equal:today'],
            'preferred_window' => ['nullable', Rule::in(['morning', 'midday', 'afternoon'])],
            'urgency' => ['required', Rule::in(['normal', 'soon'])],
            'privacy_accepted' => ['accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.regex' => 'Gunakan nomor Indonesia aktif, misalnya 081234567890.',
            'complaint.min' => 'Ceritakan sedikit lebih detail agar tim dapat melakukan triase.',
            'privacy_accepted.accepted' => 'Persetujuan privasi diperlukan untuk membuat pesanan.',
        ];
    }
}
