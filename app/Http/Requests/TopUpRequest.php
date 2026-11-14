<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TopUpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'coupon_number' => ['required', 'string', 'max:64'],
            // El token solo es obligatorio para cupones QR (ruta /topup).
            'coupon_token' => [$this->routeIs('topup') ? 'required' : 'nullable', 'string', 'max:128'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:'.config('coupons.max_amount'), 'decimal:0,2'],
        ];
    }
}
