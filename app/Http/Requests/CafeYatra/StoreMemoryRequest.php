<?php

namespace App\Http\Requests\CafeYatra;

use Illuminate\Foundation\Http\FormRequest;

class StoreMemoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', 'in:photo,video,note'],
            'description' => ['nullable', 'string', 'max:2000', 'required_if:type,note'],
            'file' => ['required_unless:type,note', 'prohibited_if:type,note', 'nullable', ...self::fileRule($this->input('type'))],
        ];
    }

    public function messages(): array
    {
        return ['description.required_if' => 'Please write something for your personal note.'];
    }

    /** File rules for a photo or video memory. */
    public static function fileRule(?string $type): array
    {
        return $type === 'video'
            ? ['file', 'mimes:mp4,mov,avi', 'max:'.config('cafeverse.max_video_kb')]
            : ['file', 'mimes:jpg,jpeg,png,webp', 'max:'.config('cafeverse.max_image_kb')];
    }
}
