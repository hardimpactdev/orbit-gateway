<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Apps;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Apps\PruneAppResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class PruneAppRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        public readonly string $app,
        public readonly bool $dryRun = false,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/apps/prune';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return [
            'app' => $this->app,
            'dry_run' => $this->dryRun,
        ];
    }

    public function createDtoFromResponse(Response $response): PruneAppResponse
    {
        $data = $this->unwrapData($response);
        $meta = $this->unwrapMeta($response);

        return new PruneAppResponse(
            app: is_string($data['app'] ?? null) ? $data['app'] : '',
            staleWorkspaces: is_array($data['stale_workspaces'] ?? null) ? array_values($data['stale_workspaces']) : [],
            warnings: is_array($meta['warnings'] ?? null) ? array_values($meta['warnings']) : [],
            dryRun: (bool) ($data['dry_run'] ?? false),
        );
    }
}
