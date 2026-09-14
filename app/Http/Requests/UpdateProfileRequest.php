<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'bio' => ['nullable', 'string', 'max:1000'],
            'birth' => ['nullable', 'date'],
            'instagram' => ['nullable', 'string', 'max:255'],
            'spotify' => ['nullable', 'string', 'max:255'],
        ];
    }
}
