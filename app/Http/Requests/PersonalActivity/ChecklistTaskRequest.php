<?php

namespace App\Http\Requests\PersonalActivity;

use Illuminate\Foundation\Http\FormRequest;

class ChecklistTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return $this->isMethod('post')
            ? ['task' => ['required', 'string', 'max:255']]
            : [
                'task' => ['sometimes', 'required', 'string', 'max:255'],
                'is_completed' => ['sometimes', 'required', 'boolean'],
            ];
    }
}
