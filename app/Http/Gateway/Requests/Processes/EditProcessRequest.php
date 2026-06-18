<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Processes;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Processes\ProcessEditResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class EditProcessRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::PATCH;

    public function __construct(
        public readonly string $app,
        public readonly string $name,
        public readonly ?string $command = null,
        public readonly ?string $restartPolicy = null,
        public readonly ?string $crashNotification = null,
        public readonly bool $restart = false,
        public readonly ?string $runtime = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/processes/'.rawurlencode($this->name);
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return array_filter([
            'app' => $this->app,
            'command' => $this->command,
            'restart_policy' => $this->restartPolicy,
            'crash_notification' => $this->crashNotification,
            'runtime' => $this->runtime,
            'restart' => $this->restart,
        ], fn (mixed $value): bool => $value !== null);
    }

    public function createDtoFromResponse(Response $response): ProcessEditResponse
    {
        $data = $this->unwrapData($response);
        $body = $response->json();
        $warnings = [];

        if (is_array($body) && isset($body['success']['meta']) && is_array($body['success']['meta'])) {
            $meta = $body['success']['meta'];

            if (isset($meta['warnings']) && is_array($meta['warnings'])) {
                $warnings = array_values($meta['warnings']);
            }
        }

        return new ProcessEditResponse(
            data: $data,
            warnings: $warnings,
        );
    }
}
