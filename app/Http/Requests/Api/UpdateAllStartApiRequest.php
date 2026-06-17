<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Http\Requests\Api\Concerns\HandlesOrbitApiValidationFailure;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAllStartApiRequest extends FormRequest
{
    use HandlesOrbitApiValidationFailure;

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
            'target_version' => ['nullable', 'string', 'filled', 'max:128'],
            'manifest_source' => ['nullable', 'string', 'filled', 'max:512'],
            'manifest_version' => ['nullable', 'string', 'filled', 'max:128'],
            'manifest' => ['nullable', 'array'],
            'gateway_image' => ['nullable', 'string', 'filled', 'max:512'],
            'cli_artifacts' => ['nullable', 'array'],
            'role_images' => ['nullable', 'array'],
        ];
    }

    protected function validationFailureFields(): array
    {
        return [
            'target_version',
            'manifest',
            'gateway_image',
            'manifest_source',
            'manifest_version',
            'cli_artifacts',
            'role_images',
        ];
    }

    protected function validationMessageFor(string $field): string
    {
        return match ($field) {
            'target_version' => 'Target version must be a string.',
            'manifest' => 'Release manifest must be an object.',
            'gateway_image' => 'Gateway image must be a string.',
            default => 'Validation failed.',
        };
    }
}
