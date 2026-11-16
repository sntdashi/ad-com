<?php

namespace App\Http\Requests;

use App\Models\ServiceOrder;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreTechnicianReviewRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $order = $this->route('order');

        return $order instanceof ServiceOrder
            && ($this->user()?->can('review', $order) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'technician_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where('role', 'technician'),
            ],
            'rating' => ['required', 'integer', 'between:1,5'],
            'quality_rating' => ['required', 'integer', 'between:1,5'],
            'communication_rating' => ['required', 'integer', 'between:1,5'],
            'punctuality_rating' => ['required', 'integer', 'between:1,5'],
            'tags' => ['nullable', 'array', 'max:5'],
            'tags.*' => [
                'string',
                'distinct',
                Rule::in(['rapi', 'komunikatif', 'tepat_waktu', 'solutif', 'ramah']),
            ],
            'comment' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('technician_id')) {
                return;
            }

            $order = $this->route('order');
            $technicianId = $this->integer('technician_id');

            if (! $order instanceof ServiceOrder || ! $this->technicianWorkedOn($order, $technicianId)) {
                $validator->errors()->add('technician_id', 'Teknisi ini tidak tercatat mengerjakan pesanan tersebut.');
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'rating.between' => 'Nilai kepuasan harus antara 1 sampai 5 bintang.',
            'quality_rating.between' => 'Nilai kualitas harus antara 1 sampai 5.',
            'communication_rating.between' => 'Nilai komunikasi harus antara 1 sampai 5.',
            'punctuality_rating.between' => 'Nilai ketepatan waktu harus antara 1 sampai 5.',
        ];
    }

    private function technicianWorkedOn(ServiceOrder $order, int $technicianId): bool
    {
        return $order->assigned_technician_id === $technicianId
            || $order->assignments()
                ->where('assignment_role', 'technician')
                ->where('user_id', $technicianId)
                ->exists();
    }
}
