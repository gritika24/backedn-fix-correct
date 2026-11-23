<?php

namespace App\Http\Requests\CafeYatra;

use Illuminate\Foundation\Http\FormRequest;

class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['qr_token' => ['required', 'uuid']];
    }

    public function messages(): array
    {
        return ['qr_token.uuid' => 'This is not a CaféVerse café QR code.'];
    }
}
