<?php

namespace App\Http\Requests\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the password reset confirmation (step 2 — submit new password).
 *
 * Requirements: 2.5, 2.6
 */
class PasswordResetConfirmRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'token'                 => ['required', 'string', 'size:64'],
            'password'              => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
            'password_confirmation' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'token.required'                 => 'Reset token is required.',
            'token.size'                     => 'Invalid reset token format.',
            'password.required'              => 'New password is required.',
            'password.min'                   => 'Password must be at least 8 characters.',
            'password.confirmed'             => 'Password confirmation does not match.',
            'password_confirmation.required' => 'Password confirmation is required.',
        ];
    }
}
