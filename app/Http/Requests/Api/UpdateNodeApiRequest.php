<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\Validator as ValidationContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Validator;

class UpdateNodeApiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'host' => ['sometimes', 'string', 'filled', 'max:255'],
            'tld' => ['sometimes', 'string', 'filled', 'regex:/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/'],
            'gateway_endpoint' => ['sometimes', 'string', 'filled', 'max:253', $this->validGatewayEndpoint(...)],
            'public_ipv4' => ['sometimes', 'string', 'filled', 'ipv4'],
            'public_ipv6' => ['sometimes', 'string', 'filled', 'ipv6'],
            'role' => ['prohibited'],
            'environment' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->hasAny(['host', 'tld', 'gateway_endpoint', 'public_ipv4', 'public_ipv6', 'role', 'environment'])) {
                return;
            }

            $validator->errors()->add('fields', 'At least one field must be provided to update a node.');
        });
    }

    /**
     * @return array<string, string>
     */
    public function updateFields(): array
    {
        /** @var array<string, string> $fields */
        $fields = $this->safe()->only(['host', 'tld', 'gateway_endpoint', 'public_ipv4', 'public_ipv6']);

        return $fields;
    }

    #[\Override]
    protected function failedValidation(ValidationContract $validator): void
    {
        $field = (string) array_key_first($validator->errors()->messages());
        $value = $this->input($field);

        throw new HttpResponseException(response()->json([
            'error' => [
                'code' => 'validation_failed',
                'message' => $this->messageFor($field, is_string($value) ? $value : null),
                'meta' => $this->metaFor($field, is_string($value) ? $value : null),
            ],
        ], 422));
    }

    private function messageFor(string $field, ?string $value): string
    {
        return match ($field) {
            'fields' => 'At least one field must be provided to update a node.',
            'role', 'environment' => "Field '{$field}' is not supported for node:update.",
            'tld' => "Invalid value for --tld: '{$value}'. TLD must be a lowercase DNS label without a leading dot.",
            'gateway_endpoint' => "Invalid value for --gateway-endpoint: '{$value}'. Gateway endpoint must be a valid IP address or dotted DNS name.",
            'public_ipv4' => "Invalid IPv4 address: '{$value}'.",
            'public_ipv6' => "Invalid IPv6 address: '{$value}'.",
            default => "Field '{$field}' cannot be empty.",
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function metaFor(string $field, ?string $value): array
    {
        if (in_array($field, ['tld', 'gateway_endpoint', 'public_ipv4', 'public_ipv6'], true)) {
            return [
                'field' => $field,
                'value' => $value,
            ];
        }

        return ['field' => $field];
    }

    private function validGatewayEndpoint(string $attribute, mixed $value, \Closure $fail): void
    {
        if (! is_string($value) || ! $this->isValidHost($value)) {
            $fail('validation.gateway_endpoint')->translate();
        }
    }

    private function isValidHost(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        if (! str_contains($host, '.')) {
            return false;
        }

        if (strlen($host) > 253 || str_contains($host, '..')) {
            return false;
        }

        $labels = explode('.', trim($host, '.'));

        foreach ($labels as $label) {
            if ($label === '' || strlen($label) > 63) {
                return false;
            }

            if (! preg_match('/^[a-zA-Z0-9](?:[a-zA-Z0-9-]*[a-zA-Z0-9])?$/', $label)) {
                return false;
            }
        }

        return true;
    }
}
