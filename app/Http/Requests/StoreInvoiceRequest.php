<?php

namespace App\Http\Requests;

use App\Models\Invoice;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Invoice::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'service_order_id' => ['nullable', 'string', 'exists:service_orders,id'],
            'customer_id' => ['nullable', 'integer', 'exists:users,id'],
            'customer_name' => ['required_without:service_order_id', 'nullable', 'string', 'max:160'],
            'customer_email' => ['nullable', 'email', 'max:160'],
            'customer_phone' => ['nullable', 'string', 'max:32'],
            'discount_amount' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'tax_rate' => ['nullable', 'regex:/^\d{1,3}(?:[.,]\d{1,2})?$/'],
            'issue_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'issue_now' => ['nullable', 'boolean'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.item_type' => ['nullable', Rule::in(['labor', 'spare_part', 'operational', 'other'])],
            'items.*.label' => ['required', 'string', 'max:200'],
            'items.*.description' => ['nullable', 'string', 'max:500'],
            'items.*.quantity' => ['required', 'regex:/^(?:0\.[1-9]\d?|0\.\d[1-9]|[1-9]\d{0,4}(?:[.,]\d{1,2})?)$/'],
            'items.*.unit_price' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'items.*.unit_cost' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'items.*.inventory_item_id' => ['nullable', 'integer', 'exists:inventory_items,id'],
            'items.*.service_order_spare_part_id' => ['nullable', 'integer', 'exists:service_order_spare_parts,id'],
        ];
    }
}
