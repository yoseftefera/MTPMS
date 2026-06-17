<?php

namespace App\Http\Requests\V1\GoodsReceipt;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for POST /api/v1/goods-receipts/{id}/inspection-result
 *
 * Requirements: 12.3
 */
class SubmitInspectionResultRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'inspector_id'            => ['required', 'uuid', 'exists:users,id'],
            'results'                 => ['required', 'array', 'min:1'],
            'results.*.grn_item_id'   => ['required', 'uuid', 'exists:goods_receipt_items,id'],
            'results.*.accepted'      => ['required', 'boolean'],
            'results.*.notes'         => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'results.required'                  => 'Inspection results are required.',
            'results.*.grn_item_id.required'    => 'Each result must reference a valid GRN item.',
            'results.*.accepted.required'       => 'Each result must specify whether the item is accepted.',
        ];
    }
}
