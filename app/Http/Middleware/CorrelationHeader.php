<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\ActivityLogCorrelation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class CorrelationHeader
{
    public function __construct(
        private ActivityLogCorrelation $correlation,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $incomingUuid = $request->header('X-Orbit-Request-Id');
        $ownsCorrelation = $this->correlation->current() === null;

        if ($ownsCorrelation) {
            $this->correlation->start(is_string($incomingUuid) && $incomingUuid !== '' ? $incomingUuid : null);
        }

        try {
            $response = $next($request);
        } finally {
            if ($ownsCorrelation) {
                $this->correlation->end();
            }
        }

        return $response;
    }
}
