<?php

declare(strict_types=1);

use App\Services\ActivityLogCorrelation;
use Illuminate\Support\Str;

describe('ActivityLogCorrelation', function (): void {
    it('start() with no argument generates a UUID', function (): void {
        $correlation = new ActivityLogCorrelation;
        $uuid = $correlation->start();

        expect(Str::isUuid($uuid))->toBeTrue();
    });

    it('start() with a provided UUID uses it', function (): void {
        $correlation = new ActivityLogCorrelation;
        $provided = (string) Str::uuid();
        $uuid = $correlation->start($provided);

        expect($uuid)->toBe($provided);
    });

    it('current() returns null before start', function (): void {
        $correlation = new ActivityLogCorrelation;

        expect($correlation->current())->toBeNull();
    });

    it('current() returns the UUID after start', function (): void {
        $correlation = new ActivityLogCorrelation;
        $uuid = $correlation->start();

        expect($correlation->current())->toBe($uuid);
    });

    it('start() called twice returns the same UUID', function (): void {
        $correlation = new ActivityLogCorrelation;
        $first = $correlation->start();
        $second = $correlation->start();

        expect($first)->toBe($second);
    });

    it('end() resets to null', function (): void {
        $correlation = new ActivityLogCorrelation;
        $correlation->start();
        $correlation->end();

        expect($correlation->current())->toBeNull();
    });
});
