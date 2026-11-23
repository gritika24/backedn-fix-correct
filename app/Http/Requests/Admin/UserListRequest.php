<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** Optional ?search=, ?role= and ?per_page= filters for user lists. */
class UserListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', 'in:user,cafe_owner,admin'],
            'per_page' => ['nullable', 'integer', 'between:5,50'],
        ];
    }
}
