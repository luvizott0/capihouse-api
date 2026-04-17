<?php

namespace App\Http\Requests\Api\V1\Post;

use Illuminate\Foundation\Http\FormRequest;

class IndexPostRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'category' => ['nullable', 'in:photo,video,tweet'],
            'cursor' => ['nullable', 'string'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'category.in' => 'Categoria inválida. Use: photo, video ou tweet.',
            'per_page.integer' => 'O parâmetro per_page deve ser numérico.',
            'per_page.min' => 'O parâmetro per_page deve ser maior que zero.',
            'per_page.max' => 'O parâmetro per_page deve ser no máximo 50.',
        ];
    }
}
