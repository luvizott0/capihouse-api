<?php

namespace App\Http\Requests\Api\V1\Feeling;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;

class StoreFeelingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $name = $this->input('name');
        $color = $this->input('color');

        $this->merge([
            'name' => is_string($name) ? trim($name) : $name,
            'color' => is_string($color) ? trim(mb_strtolower($color)) : $color,
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('feelings', 'name')->where(function (Builder $query): Builder {
                    return $query->whereNull('deleted_at');
                }),
            ],
            'color' => ['required', 'string', 'regex:/^#[0-9a-f]{6}$/'],
            'emoji' => ['required', 'string', 'max:32'],
            'is_active' => ['nullable', 'boolean'],
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
            'name.required' => 'O nome do sentimento e obrigatorio.',
            'name.unique' => 'Ja existe um sentimento com este nome.',
            'name.max' => 'O nome do sentimento deve ter no maximo 100 caracteres.',
            'color.required' => 'A cor e obrigatoria.',
            'color.regex' => 'A cor deve estar no formato hexadecimal #rrggbb.',
            'emoji.required' => 'O emoji e obrigatorio.',
            'emoji.max' => 'O emoji deve ter no maximo 32 caracteres.',
            'is_active.boolean' => 'is_active deve ser verdadeiro ou falso.',
        ];
    }
}
