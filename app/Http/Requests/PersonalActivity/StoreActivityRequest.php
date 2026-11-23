<?php

namespace App\Http\Requests\PersonalActivity;

use Illuminate\Foundation\Http\FormRequest;

class StoreActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', 'in:goal,challenge'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'tracking_type' => ['required', 'in:timer,checklist'],
            'deadline' => ['nullable', 'date', 'after_or_equal:today'],
            'icon' => ['nullable', 'in:book,run,coffee,laptop,heart,star'],
            'target_duration_minutes' => [
                'required_if:tracking_type,timer',
                'prohibited_unless:tracking_type,timer',
                'nullable', 'integer', 'min:1', 'max:10080',
            ],
            'tasks' => [
                'required_if:tracking_type,checklist',
                'prohibited_unless:tracking_type,checklist',
                'array', 'min:1', 'max:50',
            ],
            'tasks.*' => ['required', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'deadline.after_or_equal' => 'The deadline cannot be in the past.',
            'target_duration_minutes.max' => 'The target can be at most 7 days (10080 minutes).',
        ];
    }
}
