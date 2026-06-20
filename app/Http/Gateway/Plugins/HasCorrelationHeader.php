<?php

declare(strict_types=1);

namespace App\Http\Gateway\Plugins;

use App\Services\ActivityLogCorrelation;
use Saloon\Http\PendingRequest;

trait HasCorrelationHeader
{
    public function bootHasCorrelationHeader(PendingRequest $pendingRequest): void
    {
        $uuid = app(ActivityLogCorrelation::class)->current();

        if (is_string($uuid) && $uuid !== '') {
            $pendingRequest->headers()->add('X-Orbit-Request-Id', $uuid);
        }

        $pendingRequest->headers()->add(
            'X-Orbit-Client',
            $this->orbitClientName(),
        );
    }

    protected function orbitClientName(): string
    {
        return app()->runningInConsole() ? 'cli' : 'api';
    }
}
