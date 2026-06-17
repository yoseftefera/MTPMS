<?php

namespace App\Http\Requests\V1\GoodsReceipt;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for POST /api/v1/goods-receipts
 *
 * Requirements: 12.1
 */
class StoreGoodsReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'purchase_order_id'    => ['required', 'uuid', 'exists:purchase_orders,id'],
            'warehouse_id'         => ['required', 'uuid', 'exists:warehouses,id'],
            'delivery_note_number' => ['required', 'string', 'max:100'],
            'items'                => ['required', 'array', 'min:1'],
            'items.*.po_item_id'   => ['required', 'uuid', 'exists:purchase_order_items,id'],
            'items.*.received_quantity' => ['required', 'numeric', 'gt:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.required'                       => 'At least one line item is required.',
            'items.*.po_item_id.required'          => 'Each item must reference a valid purchase order item.',
            'items.*.received_quantity.required'   => 'Received quantity is required for each item.',
            'items.*.received_quantity.gt'         => 'Received quantity must be greater than zero.',
        ];
    }
}
