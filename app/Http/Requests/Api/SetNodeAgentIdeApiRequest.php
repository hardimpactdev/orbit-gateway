<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\Validator as ValidationContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class SetNodeAgentIdeApiRequest extends FormRequest
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
            'agent_ide' => ['required', 'string', 'filled'],
        ];
    }

    public function agentIde(): string
    {
        /** @var string $agentIde */
        $agentIde = $this->validated('agent_ide');

        return $agentIde;
    }

    #[\Override]
    protected function failedValidation(ValidationContract $validator): void
    {
        throw new HttpResponseException(response()->json([
            'error' => [
                'code' => 'validation_failed',
                'message' => 'Agent IDE adapter is required.',
                'meta' => ['field' => 'agent_ide'],
            ],
        ], 422));
    }
}
