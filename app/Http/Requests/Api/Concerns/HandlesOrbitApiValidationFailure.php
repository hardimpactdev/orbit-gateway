<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Concerns;

use Illuminate\Contracts\Validation\Validator as ValidationContract;
use Illuminate\Http\Exceptions\HttpResponseException;

trait HandlesOrbitApiValidationFailure
{
    #[\Override]
    protected function failedValidation(ValidationContract $validator): void
    {
        $field = $this->validationFailureField($validator);

        throw new HttpResponseException(response()->json([
            'error' => [
                'code' => 'validation_failed',
                'message' => $this->validationMessageFor($field),
                'meta' => [
                    'field' => $field,
                ],
            ],
        ], 422));
    }

    protected function validationFailureFields(): array
    {
        return [];
    }

    protected function validationMessageFor(string $field): string
    {
        return 'Validation failed.';
    }

    private function validationFailureField(ValidationContract $validator): string
    {
        $messages = $validator->errors()->messages();

        foreach ($this->validationFailureFields() as $field) {
            if (array_key_exists((string) $field, $messages)) {
                return $field;
            }
        }

        return (string) array_key_first($messages);
    }
}
