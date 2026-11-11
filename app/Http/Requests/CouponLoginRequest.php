<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CouponLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** La app anterior enviaba `email`; ahora se acepta también `username`. */
    protected function prepareForValidation(): void
    {
        if (! $this->filled('username') && $this->filled('email')) {
            $this->merge(['username' => $this->input('email')]);
        }
    }

    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ];
    }
}
