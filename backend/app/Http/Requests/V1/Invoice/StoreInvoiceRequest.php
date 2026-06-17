<?php

namespace App\Http\Requests\V1\Invoice;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for POST /api/v1/invoices
 *
 * Requirements: 14.1, 14.2
 */
class StoreInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'supplier_id'       => ['required', 'uuid', 'exists:suppliers,id'],
            'purchase_order_id' => ['nullable', 'uuid', 'exists:purchase_orders,id'],
            'contract_id'       => ['nullable', 'uuid', 'exists:contracts,id'],
            'total_amount'      => ['required', 'numeric', 'gt:0'],
            'currency'          => ['nullable', 'string', 'size:3'],
            'invoice_date'      => ['required', 'date'],
            'due_date'          => ['required', 'date', 'after_or_equal:invoice_date'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (empty($this->purchase_order_id) && empty($this->contract_id)) {
                $validator->errors()->add(
                    'purchase_order_id',
                    'An invoice must reference either a Purchase Order or a Contract.'
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'due_date.after_or_equal' => 'The due date must be on or after the invoice date.',
        ];
    }
}
