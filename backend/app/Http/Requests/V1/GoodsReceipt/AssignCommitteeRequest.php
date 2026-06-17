<?php

namespace App\Http\Requests\V1\GoodsReceipt;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for POST /api/v1/goods-receipts/{id}/assign-committee
 *
 * Requirements: 12.2
 */
class AssignCommitteeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'committee_user_ids'   => ['required', 'array', 'min:2'],
            'committee_user_ids.*' => ['required', 'uuid', 'exists:users,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'committee_user_ids.min'   => 'At least 2 Committee_Members must be assigned.',
            'committee_user_ids.*.exists' => 'One or more specified users do not exist.',
        ];
    }
}
