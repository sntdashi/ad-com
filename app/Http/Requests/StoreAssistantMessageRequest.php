<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAssistantMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'min:2', 'max:1200'],
            'messages' => ['sometimes', 'array', 'max:8'],
            'messages.*' => ['array:role,content'],
            'messages.*.role' => ['required', Rule::in(['user', 'assistant'])],
            'messages.*.content' => ['required', 'string', 'min:1', 'max:1200'],
            'page_context' => ['sometimes', 'array'],
            'page_context.route' => ['nullable', 'string', 'max:120'],
            'page_context.order_id' => ['nullable', 'string', 'max:64'],
            'page_context.period' => ['nullable', 'string', Rule::in(['daily', 'weekly', 'monthly', 'quarterly', 'yearly', 'custom'])],
            'page_context.section' => ['nullable', 'string', 'max:40'],
            'page_context.start_date' => ['nullable', 'date_format:Y-m-d'],
            'page_context.end_date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $merged = [
            'message' => trim((string) $this->input('message')),
        ];

        if ($this->has('messages')) {
            $merged['messages'] = collect(is_array($this->input('messages')) ? $this->input('messages') : [])
                ->map(fn (mixed $message): mixed => is_array($message)
                    ? [...$message, 'content' => trim((string) ($message['content'] ?? ''))]
                    : $message)
                ->all();
        }

        $this->merge($merged);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'message.required' => 'Tulis dulu pertanyaan atau masalah teknologimu.',
            'message.min' => 'Pesan terlalu singkat untuk dipahami ADI.',
            'message.max' => 'Pesan maksimal 1.200 karakter.',
            'messages.max' => 'Riwayat percakapan maksimal 8 pesan.',
            'messages.*.role.in' => 'Peran pesan tidak valid.',
            'messages.*.content.max' => 'Setiap pesan riwayat maksimal 1.200 karakter.',
        ];
    }
}
