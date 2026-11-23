<?php

namespace App\Http\Requests\Reward;

use Illuminate\Foundation\Http\FormRequest;

class RewardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $editing = $this->isMethod('put') || $this->isMethod('patch');
        $required = $editing ? ['sometimes', 'required'] : ['required'];

        return [
            'cafe_id' => $editing ? ['required', 'integer'] : ['prohibited'],
            'title' => [...$required, 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'reward_type' => [...$required, 'in:free_item,discount,special_offer'],
            'required_visits' => [...$required, 'integer', 'min:1', 'max:365'],
            'is_active' => ['sometimes', 'boolean'],
            'is_repeatable' => ['sometimes', 'boolean'],
        ];
    }
}
