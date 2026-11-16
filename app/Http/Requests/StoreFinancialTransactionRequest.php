<?php

namespace App\Http\Requests;

use App\Models\FinancialTransaction;
use App\Services\Finance\InvoiceAndPaymentService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFinancialTransactionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', FinancialTransaction::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(InvoiceAndPaymentService $service): array
    {
        return [
            'service_order_id' => ['nullable', 'string', 'exists:service_orders,id'],
            'invoice_id' => ['nullable', 'string', 'exists:invoices,id'],
            'type' => ['required', Rule::in(['income', 'expense'])],
            'category' => ['required', 'string', 'max:64'],
            'amount' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'payment_method' => ['required', Rule::in($service->allowedPaymentMethodValues())],
            'reference' => ['nullable', 'string', 'max:96'],
            'idempotency_key' => ['nullable', 'string', 'max:96'],
            'description' => ['nullable', 'string', 'max:1000'],
            'occurred_at' => ['required', 'date'],
            'proof' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ];
    }
}
