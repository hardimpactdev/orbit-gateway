<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Operations;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Operations\UpdateAllResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class UpdateAllRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::POST;

    public function resolveEndpoint(): string
    {
        return '/api/update/all';
    }

    public function createDtoFromResponse(Response $response): UpdateAllResponse
    {
        $data = $this->unwrapData($response);
        $meta = $this->unwrapMeta($response);

        $updates = $data['updates'] ?? [];
        $summary = $meta['summary'] ?? [];

        return new UpdateAllResponse(
            updates: is_array($updates) ? $updates : [],
            summary: is_array($summary) ? $summary : [],
        );
    }
}
