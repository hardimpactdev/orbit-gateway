<?php

declare(strict_types=1);

use App\Services\ActivityLogCorrelation;
use Illuminate\Support\Str;

describe('ActivityLogCorrelation', function (): void {
    beforeEach(function (): void {
        app(ActivityLogCorrelation::class)->end();
    });

    afterEach(function (): void {
        app(ActivityLogCorrelation::class)->end();
    });

    it('starts a new uuid when none is provided', function (): void {
        $correlation = app(ActivityLogCorrelation::class);

        $uuid = $correlation->start();

        expect(Str::isUuid($uuid))->toBeTrue();
        expect($correlation->current())->toBe($uuid);
    });

    it('uses the provided uuid when given', function (): void {
        $correlation = app(ActivityLogCorrelation::class);
        $incomingUuid = (string) Str::uuid();

        $uuid = $correlation->start($incomingUuid);

        expect($uuid)->toBe($incomingUuid);
        expect($correlation->current())->toBe($incomingUuid);
    });

    it('returns existing uuid without overwriting when called twice', function (): void {
        $correlation = app(ActivityLogCorrelation::class);
        $firstUuid = $correlation->start();
        $secondUuid = $correlation->start((string) Str::uuid());

        expect($secondUuid)->toBe($firstUuid);
    });

    it('returns null after end is called', function (): void {
        $correlation = app(ActivityLogCorrelation::class);
        $correlation->start();
        $correlation->end();

        expect($correlation->current())->toBeNull();
    });

    it('allows restarting after end', function (): void {
        $correlation = app(ActivityLogCorrelation::class);
        $firstUuid = $correlation->start();
        $correlation->end();
        $secondUuid = $correlation->start();

        expect($secondUuid)->not->toBe($firstUuid);
        expect(Str::isUuid($secondUuid))->toBeTrue();
    });
});
