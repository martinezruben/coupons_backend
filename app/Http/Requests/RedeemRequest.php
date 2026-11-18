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
            // Localidad donde se hace el cobro; si no viene, se usa la del dispositivo registrado
            // Surtidor de la localidad (1 a 20; el máximo real es el de la localidad del dispositivo)
            'dispenser' => ['nullable', 'integer', 'between:1,20'],
            'location_id' => ['nullable', 'uuid', 'exists:locations,id'],
        ];
    }
}
