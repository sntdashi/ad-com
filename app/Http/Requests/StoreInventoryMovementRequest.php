<?php

namespace App\Http\Requests;

use App\Models\InventoryItem;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInventoryMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        $item = InventoryItem::query()->find($this->integer('inventory_item_id'));

        return $item !== null && ($this->user()?->can('update', $item) ?? false);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $isAdjustment = $this->input('type') === 'adjustment';

        return [
            'inventory_item_id' => ['required', 'integer', Rule::exists('inventory_items', 'id')->where('is_active', true)],
            'service_order_id' => ['nullable', 'string', 'exists:service_orders,id'],
            'type' => ['required', Rule::in(['stock_in', 'stock_out', 'adjustment'])],
            'quantity' => [
                Rule::requiredIf(fn (): bool => ! $isAdjustment),
                'nullable',
                'integer',
                'min:1',
                'max:1000000',
            ],
            'new_quantity' => [
                Rule::requiredIf(fn (): bool => $isAdjustment),
                'nullable',
                'integer',
                'min:0',
                'max:1000000',
            ],
            'unit_cost' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'reference' => ['nullable', 'string', 'max:96'],
            'supplier_name' => ['nullable', 'string', 'max:255'],
            'adjustment_reason' => [
                Rule::requiredIf(fn (): bool => $isAdjustment),
                'nullable',
                'string',
                'max:500',
            ],
            'notes' => ['nullable', 'string', 'max:1000'],
            'occurred_at' => ['nullable', 'date'],
            'record_finance_expense' => ['sometimes', 'boolean'],
            'payment_method' => ['nullable', Rule::in(['bank_transfer', 'cash', 'qr', 'other'])],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'inventory_item_id.exists' => 'Barang inventaris tidak tersedia.',
            'quantity.min' => 'Jumlah mutasi minimal satu.',
            'new_quantity.required' => 'Jumlah stok baru wajib diisi untuk penyesuaian.',
            'adjustment_reason.required' => 'Alasan penyesuaian stok wajib diisi.',
            'service_order_id.exists' => 'Tiket terkait tidak ditemukan.',
        ];
    }
}
