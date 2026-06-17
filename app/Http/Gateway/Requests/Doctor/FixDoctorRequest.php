<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Doctor;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Doctor\DoctorRunResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class FixDoctorRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    /**
     * @param  list<string>  $families
     * @param  list<array<string, mixed>>|null  $issues
     */
    public function __construct(
        public readonly string $mode,
        public readonly array $families = [],
        public readonly ?array $issues = null,
        public readonly ?string $node = null,
        public readonly bool $self = false,
        public readonly ?string $app = null,
        public readonly ?string $workspace = null,
        public readonly ?string $key = null,
        public readonly bool $dryRun = false,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/doctor/fix';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return array_filter([
            'mode' => $this->mode,
            'families' => $this->families,
            'issues' => $this->issues,
            'node' => $this->node,
            'self' => $this->self,
            'app' => $this->app,
            'workspace' => $this->workspace,
            'key' => $this->key,
            'dry_run' => $this->dryRun,
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
