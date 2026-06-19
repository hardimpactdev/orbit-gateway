<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Closure;
use Illuminate\Contracts\Validation\Validator as ValidationContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Validator;

class NodePermissionsApiRequest extends FormRequest
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
            'add' => ['nullable', 'string', 'filled', 'max:4096'],
            'remove' => ['nullable', 'string', 'filled', 'max:4096'],
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

    public function addInput(): ?string
    {
        $value = $this->input('add');

        return is_string($value) ? $value : null;
    }

    public function removeInput(): ?string
    {
        $value = $this->input('remove');

        return is_string($value) ? $value : null;
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

    /**
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $modeCount = 0;
                foreach (['preset', 'permissions', 'add', 'remove'] as $field) {
                    if ($this->input($field) !== null) {
                        $modeCount++;
                    }
                }

                if ($modeCount > 1) {
                    $validator->errors()->add('mode', 'Use only one of preset, permissions, add, or remove.');
                }
            },
        ];
    }

    private function messageFor(string $field): string
    {
        return match ($field) {
            'serving_node' => 'Serving node is required.',
            'consuming_node' => 'Consuming node is required.',
            'preset' => 'Preset must be a non-empty string.',
            'permissions' => 'Permissions must be a non-empty string.',
            'add' => 'Add permissions must be a non-empty string.',
            'remove' => 'Remove permissions must be a non-empty string.',
            default => 'Invalid input provided.',
        };
    }
}
