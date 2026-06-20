<?php

declare(strict_types=1);

use App\Http\Middleware\CorrelationHeader;
use App\Services\ActivityLogCorrelation;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

describe('CorrelationHeader middleware', function (): void {
    it('propagates incoming X-Orbit-Request-Id to correlation context', function (): void {
        $correlation = app(ActivityLogCorrelation::class);
        $incomingUuid = (string) Str::uuid();

        $request = Request::create('/api/test');
        $request->headers->set('X-Orbit-Request-Id', $incomingUuid);

        $middleware = new CorrelationHeader($correlation);

        $middleware->handle($request, function (Request $request): Response {
            return new Response('ok');
        });

        expect($correlation->current())->toBeNull();
    });

    it('generates a UUID when no incoming header is present', function (): void {
        $correlation = app(ActivityLogCorrelation::class);

        $request = Request::create('/api/test');

        $middleware = new CorrelationHeader($correlation);
        $capturedUuid = null;

        $middleware->handle($request, function (Request $request) use (&$capturedUuid): Response {
            $capturedUuid = app(ActivityLogCorrelation::class)->current();

            return new Response('ok');
        });

        expect($capturedUuid)->not->toBeNull()
            ->and(Str::isUuid($capturedUuid))->toBeTrue()
            ->and($correlation->current())->toBeNull();
    });

    it('preserves outer correlation UUID when nested', function (): void {
        $correlation = app(ActivityLogCorrelation::class);
        $outerUuid = (string) Str::uuid();
        $correlation->start($outerUuid);

        $request = Request::create('/api/test');
        $request->headers->set('X-Orbit-Request-Id', (string) Str::uuid());

        $middleware = new CorrelationHeader($correlation);

        $middleware->handle($request, function (Request $request): Response {
            return new Response('ok');
        });

        expect($correlation->current())->toBe($outerUuid);

        $correlation->end();
    });

    it('resets correlation after request even on exceptions', function (): void {
        $correlation = app(ActivityLogCorrelation::class);

        $request = Request::create('/api/test');

        $middleware = new CorrelationHeader($correlation);

        try {
            $middleware->handle($request, function (Request $request): Response {
                throw new RuntimeException('Boom');
            });
        } catch (RuntimeException) {
            // expected
        }

        expect($correlation->current())->toBeNull();
    });
});
