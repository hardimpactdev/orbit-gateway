<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Doctor;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Doctor\DoctorRunResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class RunDoctorRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    /**
     * @param  list<string>  $families
     */
    public function __construct(
        public readonly array $families = [],
        public readonly ?string $node = null,
        public readonly bool $self = false,
        public readonly ?string $app = null,
        public readonly ?string $workspace = null,
        public readonly ?string $key = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/doctor/run';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return array_filter([
            'families' => $this->families,
            'node' => $this->node,
            'self' => $this->self,
            'app' => $this->app,
            'workspace' => $this->workspace,
            'key' => $this->key,
        ], static fn (mixed $value): bool => $value !== null && $value !== [] && $value !== false);
    }

    public function createDtoFromResponse(Response $response): DoctorRunResponse
    {
        $data = $this->unwrapData($response);
        $doctor = $data['doctor'] ?? [];

        return new DoctorRunResponse(
            doctor: is_array($doctor) ? $doctor : [],
        );
    }
}
