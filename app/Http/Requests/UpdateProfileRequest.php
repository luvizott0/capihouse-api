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
            'name'                 => ['sometimes', 'string', 'max:255'],
            'bio'                  => ['nullable', 'string', 'max:1000'],
            'birth'                => ['nullable', 'date'],
            'instagram'            => ['nullable', 'string', 'max:255'],
            'spotify'              => ['nullable', 'string', 'max:255'],
            'theme'                => ['sometimes', 'nullable', 'array'],
            'theme.bg_type'        => ['sometimes', 'nullable', 'in:color,image'],
            'theme.bg_value'       => ['sometimes', 'nullable', 'string', 'max:512'],
            'theme.bg_size'        => ['sometimes', 'nullable', 'string', 'max:64'],
            'theme.bg_repeat'      => ['sometimes', 'nullable', 'in:no-repeat,repeat,repeat-x,repeat-y'],
            'theme.bg_position'    => ['sometimes', 'nullable', 'string', 'max:64'],
            'theme.color_primary'  => ['sometimes', 'nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ];
    }
}
