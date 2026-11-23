<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'email' => strtolower(trim((string) $this->input('email'))),
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:100', "regex:/^[\\pL\\pM]+(?:[ .'-][\\pL\\pM]+)*$/u"],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', 'string', 'in:user,cafe_owner'],
            'password' => ['required', 'string', 'confirmed', self::passwordRule()],
        ];
    }

    public function messages(): array
    {
        return [
            'name.regex' => 'Name can only contain letters, spaces, dots, apostrophes and hyphens.',
            'role.required' => 'Please choose whether you are a customer or a café owner.',
            'role.in' => 'Please choose whether you are a customer or a café owner.',
            'email.unique' => 'An account with this email already exists.',
        ];
    }

    /** Shared by registration and password change so the rules never drift apart. */
    public static function passwordRule(): Password
    {
        return Password::min(8)->max(128)->mixedCase()->numbers()->symbols();
    }
}
