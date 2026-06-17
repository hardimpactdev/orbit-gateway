<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\Validator as ValidationContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class GrantNodeApiRequest extends FormRequest
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
            'preset' => ['nullable', 'string', 'filled', 'max:255'],
            'permissions' => ['nullable', 'string', 'filled', 'max:4096'],
            'force' => ['nullable', 'boolean'],
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

    public function preset(): ?string
    {
        $value = $this->input('preset');

        return is_string($value) ? $value : null;
    }

    public function permissionsInput(): ?string
    {
        $value = $this->input('permissions');

        return is_string($value) ? $value : null;
    }

    public function force(): bool
    {
        $value = $this->input('force');

        return $value === true || $value === 'true' || $value === '1' || $value === 1;
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
            default => 'Consuming node is required.',
        };
    }
}
