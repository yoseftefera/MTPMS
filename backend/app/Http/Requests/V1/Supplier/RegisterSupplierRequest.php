<?php

namespace App\Http\Requests\V1\Supplier;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the public supplier self-registration payload.
 *
 * This request is used on the unauthenticated endpoint — no auth check is needed.
 *
 * Requirements: 7.1
 */
class RegisterSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Public endpoint — no authentication required.
        return true;
    }

    public function rules(): array
    {
        return [
            'organization_name' => ['required', 'string', 'max:255'],
            'contact_name'      => ['required', 'string', 'max:255'],
            'contact_email'     => ['required', 'email', 'max:255'],
            'contact_phone'     => ['nullable', 'string', 'max:50'],
            'business_category' => ['required', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'organization_name.required' => 'The organization name is required.',
            'organization_name.max'      => 'The organization name may not exceed 255 characters.',
            'contact_name.required'      => 'The contact name is required.',
            'contact_name.max'           => 'The contact name may not exceed 255 characters.',
            'contact_email.required'     => 'The contact email is required.',
            'contact_email.email'        => 'The contact email must be a valid email address.',
            'contact_email.max'          => 'The contact email may not exceed 255 characters.',
            'contact_phone.max'          => 'The contact phone may not exceed 50 characters.',
            'business_category.required' => 'The business category is required.',
            'business_category.max'      => 'The business category may not exceed 100 characters.',
        ];
    }
}
