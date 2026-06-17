<?php

namespace App\Http\Requests\V1\Tender;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the payload for publishing a tender.
 *
 * For single_source tenders a `supplier_id` is required.
 *
 * Requirements: 8.2, 8.10
 */
class PublishTenderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Permission enforced at route level via role.check:tenders.publish
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // supplier_id is conditionally required; service-layer validates
            // the single_source constraint and throws InvalidArgumentException.
            'supplier_id' => ['nullable', 'uuid'],
        ];
    }
}
