<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\Validator as ValidationContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class RevokeNodeApiRequest extends FormRequest
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
            'consuming_node' => ['required', 'string', 'filled', 'max:255'],
            'serving_node' => ['required', 'string', 'filled', 'max:255'],
            'force' => ['required', 'accepted'],
        ];
    }

    public function consumingNodeName(): string
    {
        return (string) $this->validated('consuming_node');
    }

    public function servingNodeName(): string
    {
        return (string) $this->validated('serving_node');
    }

    #[\Override]
    protected function failedValidation(ValidationContract $validator): void
    {
        $field = (string) array_key_first($validator->errors()->messages());

        throw new HttpResponseException(response()->json([
            'error' => [
                'code' => 'validation_failed',
                'message' => $this->messageFor($field),
                'meta' => [
                    'field' => $field,
                ],
            ],
        ], 422));
    }

    private function messageFor(string $field): string
    {
        return match ($field) {
            'serving_node' => 'Serving node is required.',
            'force' => 'Use --force to revoke this grant.',
            default => 'Consuming node is required.',
        };
    }
}
