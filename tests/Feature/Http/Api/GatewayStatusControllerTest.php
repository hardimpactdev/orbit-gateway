<?php

declare(strict_types=1);

/**
 * No WireGuard peer is required to hit /api/status — it sits outside the WireGuardIdentity
 * middleware. The route is confirmed to be conflict-free (route:list --path=status returned
 * no existing routes before this controller was added).
 */
describe('GatewayStatusController', function (): void {
    it('returns 200 with a canonical success envelope', function (): void {
        $response = $this->getJson('/api/status');

        $response->assertOk()
            ->assertJsonStructure([
                'success' => [
                    'data' => [
                        'version',
                        'time',
                    ],
                    'meta',
                ],
            ]);
    });

    it('returns expected top-level envelope keys', function (): void {
        $response = $this->getJson('/api/status');

        $response->assertOk()
            ->assertJsonPath('success.data.version', config('app.version', '0.1.0'));

        $time = $response->json('success.data.time');
        expect($time)->toBeString()
            ->and((int) strtotime((string) $time))->toBeGreaterThan(0);
    });

    it('does not require WireGuard peer authentication', function (): void {
        // Send the request with no REMOTE_ADDR header (no WireGuard peer).
        $response = test()->call('GET', '/api/status', [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
        ]);

        $response->assertOk()
            ->assertJsonPath('success.data.version', config('app.version', '0.1.0'));
    });

    it('is registered under the api.status route name', function (): void {
        expect(route('api.status'))->toBeString()
            ->and(route('api.status'))->toEndWith('/api/status');
    });
});
