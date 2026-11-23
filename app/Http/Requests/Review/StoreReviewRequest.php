<?php

namespace App\Http\Requests\Review;

use Illuminate\Foundation\Http\FormRequest;

class StoreReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cafe_visit_id' => ['required', 'integer'],
            'rating' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:2000'],
            'media' => ['nullable', 'array', 'max:6'],
            'media.*' => self::mediaRule(),
        ];
    }

    public static function mediaRule(): array
    {
        return ['file', 'mimes:jpg,jpeg,png,webp,mp4,mov,avi', 'max:'.config('cafeverse.max_video_kb')];
    }
}
