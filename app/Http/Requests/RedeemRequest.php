<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RedeemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'gt:0', 'max:'.config('coupons.max_amount'), 'decimal:0,2'],
            'coupons' => ['required', 'array', 'min:1', 'max:'.config('coupons.max_coupons_per_transaction')],
            'coupons.*' => ['required', 'string', 'max:64', 'distinct'],
            'tokens' => ['nullable', 'array'],
            'tokens.*' => ['nullable', 'string', 'max:128'],
        ];
    }
}
