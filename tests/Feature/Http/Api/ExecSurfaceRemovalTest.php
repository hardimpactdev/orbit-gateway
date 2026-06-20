<?php

declare(strict_types=1);

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;

describe('exec surface removal', function (): void {
    it('does not register app or workspace exec endpoints', function (): void {
        $registeredEndpoints = collect(Route::getRoutes())
            ->map(fn (LaravelRoute $route): string => implode('|', $route->methods()).' '.$route->uri())
            ->values();

        expect($registeredEndpoints)->not->toContain(
            'POST api/apps/{app}/exec',
            'POST api/apps/exec/by-path',
            'POST api/workspaces/{name}/exec',
            'POST api/workspaces/exec/by-path',
        );
    });
});
