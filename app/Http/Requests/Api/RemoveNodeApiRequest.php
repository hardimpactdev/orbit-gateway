<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\Validator as ValidationContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class RemoveNodeApiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'destructive_consent' => ['accepted'],
        ];
    }

    #[\Override]
    protected function failedValidation(ValidationContract $validator): void
    {
        throw new HttpResponseException(response()->json([
            'error' => [
                'code' => 'validation_failed',
                'message' => 'Use --force to remove this node.',
                'meta' => [
                    'field' => 'force',
                ],
            ],
        ], 422));
    }
}
