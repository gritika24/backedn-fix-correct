<?php

namespace App\Http\Requests\Review;

use Illuminate\Foundation\Http\FormRequest;

class ReviewCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['comment' => trim((string) $this->input('comment'))]);
    }

    public function rules(): array
    {
        return ['comment' => ['required', 'string', 'max:2000']];
    }
}
