<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Http\Requests\Api\Concerns\HandlesOrbitApiValidationFailure;
use Illuminate\Foundation\Http\FormRequest;

class EnableAppWebSocketApiRequest extends FormRequest
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
            'public_hosts' => ['sometimes', 'array'],
            'public_hosts.*' => ['string', 'filled', 'max:255', 'not_regex:/\:\/\//'],
        ];
    }

    /**
     * @return list<string>
     */
    public function publicHosts(): array
    {
        $publicHosts = $this->validated('public_hosts', []);

        if (! is_array($publicHosts)) {
            return [];
        }

        return array_values(array_filter($publicHosts, is_string(...)));
    }

    /**
     * @return list<string>
     */
    protected function validationFailureFields(): array
    {
        return ['public_hosts'];
    }

    protected function validationMessageFor(string $field): string
    {
        return match ($field) {
            'public_hosts' => 'Public hosts must be an array.',
            default => 'Public hosts must be hostnames.',
        };
    }
}
