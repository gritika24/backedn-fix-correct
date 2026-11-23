<?php

namespace App\Http\Requests\CafeYatra;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMemoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // The memory type comes from the database, so the controller passes
        // the right file rules; here we only check the basics.
        return [
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'file' => ['sometimes', 'nullable', 'file', 'max:'.config('cafeverse.max_video_kb')],
        ];
    }
}
