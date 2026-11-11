<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BalanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'coupon_number' => ['required', 'string', 'max:64'],
            // El token solo es obligatorio para cupones QR (ruta /balance).
            'coupon_token' => [$this->routeIs('balance') ? 'required' : 'nullable', 'string', 'max:128'],
        ];
    }
}
