<?php

namespace App\Http\Requests\Review;

use Illuminate\Foundation\Http\FormRequest;

class UpdateReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rating' => ['sometimes', 'required', 'integer', 'between:1,5'],
            'comment' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'remove_media' => ['nullable', 'array'],
            'remove_media.*' => ['integer', 'min:0', 'distinct'],
            'media' => ['nullable', 'array', 'max:6'],
            'media.*' => StoreReviewRequest::mediaRule(),
        ];
    }
}
