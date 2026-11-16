<?php

namespace App\Http\Requests;

use App\Models\InventoryItem;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInventoryItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', InventoryItem::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('sku')) {
            $this->merge([
                'sku' => mb_strtoupper(trim((string) $this->input('sku'))),
            ]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'sku' => ['required', 'string', 'max:32', Rule::unique('inventory_items', 'sku')],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'unit' => ['required', 'string', 'max:24'],
            'quantity_on_hand' => ['required', 'integer', 'min:0', 'max:1000000'],
            'reorder_level' => ['required', 'integer', 'min:0', 'max:1000000'],
            'warranty_days' => ['nullable', 'integer', 'min:0', 'max:1825'],
            'warranty_terms' => ['nullable', 'string', 'max:500'],
            'unit_cost' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'selling_price' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'storage_location' => ['nullable', 'string', 'max:255'],
            'supplier_name' => ['nullable', 'string', 'max:255'],
            'supplier_contact' => ['nullable', 'string', 'max:64'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'sku.unique' => 'SKU sudah terdaftar pada barang inventaris lain.',
        ];
    }
}
