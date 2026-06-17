<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Deploy;

use App\Http\Gateway\Responses\Deploy\DeployResponse;
use Saloon\Http\Response;

final readonly class DeployResponseFactory
{
    public static function fromResponse(Response $response): DeployResponse
    {
        $body = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
        $success = is_array($body) ? ($body['success'] ?? []) : [];
        $data = is_array($success) ? ($success['data'] ?? []) : [];
        $meta = is_array($success) ? ($success['meta'] ?? []) : [];

        return new DeployResponse(
            data: is_array($data) ? $data : [],
            meta: is_array($meta) ? $meta : [],
        );
    }
}
