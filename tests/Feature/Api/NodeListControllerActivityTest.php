<?php

declare(strict_types=1);

use App\Models\NodeRoleAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

const NODE_ACTIVITY_WG_IP = '10.6.0.99';

describe('NodeListController activity logging', function (): void {
    beforeEach(function (): void {
        DB::table('nodes')->insert([
            'name' => 'caller',
            'host' => NODE_ACTIVITY_WG_IP,
            'orbit_path' => '/home/test/orbit',
            'status' => 'active',
            'wireguard_address' => NODE_ACTIVITY_WG_IP,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $gatewayId = (int) DB::table('nodes')->insertGetId([
            'name' => 'gw',
            'host' => '10.6.0.1',
            'orbit_path' => '/home/test/orbit',
            'status' => 'active',
            'wireguard_address' => '10.6.0.1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        NodeRoleAssignment::factory()->create([
            'node_id' => $gatewayId,
            'role' => 'gateway',
            'status' => 'active',
        ]);
    });

    it('logs activity when a Loggable controller handles a request', function (): void {
        $response = $this->call('GET', '/api/nodes', [], [], [], ['REMOTE_ADDR' => NODE_ACTIVITY_WG_IP]);

        $response->assertOk();

        $entry = Activity::query()->first();

        expect($entry)->not->toBeNull();
        expect($entry->log_name)->toBe('api');
        expect($entry->event)->toBe('api:GET /nodes');
        expect($entry->properties->get('type'))->toBe('read');
        expect($entry->properties->get('method'))->toBe('GET');
        expect($entry->properties->get('path'))->toBe('api/nodes');
        expect($entry->properties->get('served_by_name'))->toBe('gw');
        expect($entry->properties->get('served_by_wg_ip'))->toBe('10.6.0.1');
    });

    it('preserves X-Orbit-Request-Id in batch_uuid for logged activity', function (): void {
        $requestId = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

        $response = $this->call('GET', '/api/nodes', [], [], [], [
            'REMOTE_ADDR' => NODE_ACTIVITY_WG_IP,
            'HTTP_X_ORBIT_REQUEST_ID' => $requestId,
        ]);

        $response->assertOk();

        $entry = Activity::query()->first();

        expect($entry)->not->toBeNull();
        expect($entry->batch_uuid)->toBe($requestId);
    });
});
