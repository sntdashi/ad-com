<?php

namespace App\Http\Requests;

use App\Models\Invoice;
use App\Services\Finance\InvoiceAndPaymentService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordInvoicePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $invoice = $this->route('invoice');

        return $invoice instanceof Invoice
            && ($this->user()?->can('recordPayment', $invoice) ?? false);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(InvoiceAndPaymentService $service): array
    {
        return [
            'amount' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'payment_method' => ['required', Rule::in($service->allowedPaymentMethodValues())],
            'reference' => ['nullable', 'string', 'max:96'],
            'idempotency_key' => ['nullable', 'string', 'max:96'],
            'description' => ['nullable', 'string', 'max:1000'],
            'occurred_at' => ['nullable', 'date'],
            'proof' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
            'verify_immediately' => ['nullable', 'boolean'],
        ];
    }
}
