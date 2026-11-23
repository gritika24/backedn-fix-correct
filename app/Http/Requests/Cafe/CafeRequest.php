<?php

namespace App\Http\Requests\Cafe;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for creating (POST /cafes) and editing (PUT /cafes/{id}) a café.
 * On edit every field becomes optional ("sometimes"), so the app can send
 * only what changed.
 */
class CafeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Accept "08:00:00" from the database as well as "08:00".
        foreach (['opening_time', 'closing_time'] as $field) {
            $value = $this->input($field);

            if (is_string($value) && preg_match('/^\d{2}:\d{2}:\d{2}$/', $value)) {
                $this->merge([$field => substr($value, 0, 5)]);
            }
        }
    }

    public function rules(): array
    {
        $editing = $this->isMethod('put') || $this->isMethod('patch');
        $required = $editing ? ['sometimes', 'required'] : ['required'];
        $optional = $editing ? ['sometimes', 'nullable'] : ['nullable'];
        $image = ['image', 'mimes:jpg,jpeg,png,webp', 'max:'.config('cafeverse.max_image_kb')];

        return [
            'name' => [...$required, 'string', 'min:2', 'max:255'],
            'description' => [...$required, 'string', 'max:5000'],
            'contact_number' => [...$required, 'string', 'regex:/^\+?[0-9 \-]{7,20}$/'],
            'email' => [...$optional, 'email', 'max:255'],

            'address' => [...$required, 'string', 'max:500'],
            'latitude' => [...$optional, 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => [...$optional, 'numeric', 'between:-180,180', 'required_with:latitude'],
            'maps_link' => [...$optional, 'url', 'max:2000'],

            'opening_time' => [...$optional, 'date_format:H:i'],
            'closing_time' => [...$optional, 'date_format:H:i'],
            'operating_days' => [...$optional, 'array', 'max:7'],
            'operating_days.*' => ['string', 'in:Sunday,Monday,Tuesday,Wednesday,Thursday,Friday,Saturday,Sun,Mon,Tue,Wed,Thu,Fri,Sat'],
            'budget_range' => [...$optional, 'string', 'max:255'],

            'ambience' => [...$optional, 'array', 'max:30'],
            'mood' => [...$optional, 'array', 'max:30'],
            'activities' => [...$optional, 'array', 'max:30'],
            'seating' => [...$optional, 'array', 'max:30'],
            'facilities' => [...$optional, 'array', 'max:30'],
            'social_setting' => [...$optional, 'array', 'max:30'],
            'ambience.*' => ['string', 'max:100'],
            'mood.*' => ['string', 'max:100'],
            'activities.*' => ['string', 'max:100'],
            'seating.*' => ['string', 'max:100'],
            'facilities.*' => ['string', 'max:100'],
            'social_setting.*' => ['string', 'max:100'],

            'logo' => [...$optional, ...$image],
            'cover_image' => [...$optional, ...$image],
            'cafe_photos' => [...$optional, 'array', 'max:10'],
            'cafe_photos.*' => $image,
            'food_photos' => [...$optional, 'array', 'max:10'],
            'food_photos.*' => $image,
            'menu_images' => [...$optional, 'array', 'max:10'],
            'menu_images.*' => $image,
        ];
    }

    public function messages(): array
    {
        return [
            'contact_number.regex' => 'Enter a valid contact number.',
            'operating_days.*.in' => 'Operating days must be valid weekday names.',
        ];
    }
}
