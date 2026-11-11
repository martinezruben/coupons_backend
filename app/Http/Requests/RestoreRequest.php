<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RestoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'coupon_number' => ['required', 'string', 'max:64'],
            'coupon_amount' => ['required', 'numeric', 'gte:0', 'decimal:0,2'],
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ];
    }
}
