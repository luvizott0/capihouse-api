<?php

namespace App\Http\Requests\Api\V1\Post;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;

class StorePostRequest extends FormRequest
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
        $content = $this->input('content');
        $normalizedContent = is_string($content) ? trim($content) : null;

        $hashtagsInput = $this->input('hashtags', []);

        if (is_string($hashtagsInput)) {
            $decoded = json_decode($hashtagsInput, true);

            if (is_array($decoded)) {
                $hashtagsInput = $decoded;
            } elseif ($hashtagsInput === '') {
                $hashtagsInput = [];
            } else {
                $hashtagsInput = explode(',', $hashtagsInput);
            }
        }

        if (! is_array($hashtagsInput)) {
            $hashtagsInput = [];
        }

        $normalizedHashtags = collect($hashtagsInput)
            ->filter(fn (mixed $tag): bool => is_string($tag))
            ->map(fn (string $tag): string => ltrim(trim(mb_strtolower($tag)), '#'))
            ->filter(fn (string $tag): bool => $tag !== '')
            ->unique()
            ->values()
            ->all();

        $this->merge([
            'content' => $normalizedContent === '' ? null : $normalizedContent,
            'hashtags' => $normalizedHashtags,
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
            'content' => ['nullable', 'string'],
            'feeling_id' => [
                'nullable',
                'integer',
                Rule::exists('feelings', 'id')->where(function (Builder $query): Builder {
                    return $query
                        ->where('is_active', true)
                        ->whereNull('deleted_at');
                }),
            ],
            'hashtags' => ['nullable', 'array'],
            'hashtags.*' => ['string', 'max:100', 'regex:/^[a-z0-9_]+$/'],
            'media' => ['nullable', 'array'],
            'media.*' => ['file'],
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $content = trim((string) ($this->input('content') ?? ''));
            $media = $this->file('media', []);

            if (! is_array($media)) {
                $media = $media ? [$media] : [];
            }

            if ($content === '' && count($media) === 0) {
                $validator->errors()->add('content', 'Informe texto ou anexe pelo menos uma midia.');
            }

            $allowedImageMimes = ['image/jpeg', 'image/png', 'image/webp'];
            $allowedVideoMimes = ['video/mp4', 'video/webm', 'video/quicktime'];

            $maxImageBytes = 3 * 1024 * 1024;
            $maxVideoBytes = 25 * 1024 * 1024;
            $imageCount = 0;

            foreach ($media as $index => $file) {
                if (! $file) {
                    continue;
                }

                $mimeType = $file->getMimeType() ?? '';
                $size = $file->getSize() ?? 0;

                if (in_array($mimeType, $allowedImageMimes, true)) {
                    $imageCount++;

                    if ($size > $maxImageBytes) {
                        $validator->errors()->add("media.$index", 'Imagem excede o limite de 3MB.');
                    }

                    continue;
                }

                if (in_array($mimeType, $allowedVideoMimes, true)) {
                    if ($size > $maxVideoBytes) {
                        $validator->errors()->add("media.$index", 'Video excede o limite de 25MB.');
                    }

                    continue;
                }

                $validator->errors()->add("media.$index", 'Tipo de arquivo nao permitido.');
            }

            if ($imageCount > 20) {
                $validator->errors()->add('media', 'No maximo 20 imagens por post.');
            }
        });
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'hashtags.array' => 'As hashtags devem ser enviadas em lista.',
            'hashtags.*.max' => 'Cada hashtag deve ter no maximo 100 caracteres.',
            'hashtags.*.regex' => 'Hashtag invalida. Use apenas letras sem acento, numeros e underscore.',
            'feeling_id.exists' => 'Sentimento invalido ou inativo.',
        ];
    }
}
