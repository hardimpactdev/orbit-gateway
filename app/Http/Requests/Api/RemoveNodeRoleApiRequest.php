<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Http\Requests\Api\Concerns\HandlesOrbitApiValidationFailure;
use Illuminate\Foundation\Http\FormRequest;

class RemoveNodeRoleApiRequest extends FormRequest
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
            'force' => ['nullable', 'boolean'],
            'purge_data' => ['nullable', 'boolean'],
        ];
    }

    public function force(): bool
    {
        return (bool) $this->boolean('force');
    }

    public function purgeData(): bool
    {
        return (bool) $this->boolean('purge_data');
    }

    protected function validationFailureFields(): array
    {
        return ['force', 'purge_data'];
    }

    protected function validationMessageFor(string $field): string
    {
        return match ($field) {
            'purge_data' => 'purge_data must be true or false.',
            default => 'force must be true or false.',
        };
    }
}
