<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Http\Requests\Api\Concerns\HandlesOrbitApiValidationFailure;
use Illuminate\Foundation\Http\FormRequest;

class AddNodeRoleApiRequest extends FormRequest
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
            'role' => ['required', 'string', 'filled', 'max:255'],
            'settings' => ['nullable', 'array'],
            'ingress_node' => ['nullable', 'string'],
            'postgres_node' => ['nullable', 'string'],
            'clickhouse_node' => ['nullable', 'string'],
        ];
    }

    public function role(): string
    {
        return (string) $this->validated('role');
    }

    /**
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        $settings = $this->validated('settings', []);

        return is_array($settings) ? $settings : [];
    }

    public function ingressNode(): ?string
    {
        $value = $this->validated('ingress_node');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function postgresNode(): ?string
    {
        $value = $this->validated('postgres_node');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function clickhouseNode(): ?string
    {
        $value = $this->validated('clickhouse_node');

        return is_string($value) && $value !== '' ? $value : null;
    }

    protected function validationFailureFields(): array
    {
        return ['role', 'settings', 'ingress_node', 'postgres_node', 'clickhouse_node'];
    }

    protected function validationMessageFor(string $field): string
    {
        return match ($field) {
            'settings' => 'Settings must be an object.',
            'ingress_node' => 'Ingress node must be a string.',
            'postgres_node' => 'PostgreSQL node must be a string.',
            'clickhouse_node' => 'ClickHouse node must be a string.',
            default => 'Role is required.',
        };
    }
}
