<?php

declare(strict_types=1);

use App\Models\App;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

const ACTIVITY_LIST_CALLER_WG_IP = '10.6.0.99';

function createActivityListCallerNode(array $overrides = []): Node
{
    $node = Node::factory()->create(array_merge([
        'name' => 'caller',
        'host' => ACTIVITY_LIST_CALLER_WG_IP,
        'wireguard_address' => ACTIVITY_LIST_CALLER_WG_IP,
    ], $overrides));

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'gateway',
        'status' => 'active',
    ]);

    return $node;
}

function createActivityEntry(
    string $type,
    string $effect,
    Node $actor,
    ?Model $subject = null,
    ?string $correlation = null,
): Activity {
    $logger = activity('api')
        ->causedBy($actor)
        ->event($type)
        ->withProperties([
            'type' => $effect,
            'command' => str_replace('.', ':', $type),
        ]);

    if ($subject !== null) {
        $logger = $logger->performedOn($subject);
    }

    $activity = $logger->log("Recorded {$type}");

    if ($correlation !== null) {
        $activity->forceFill(['batch_uuid' => $correlation])->save();
    }

    return $activity;
}

describe('ActivityListController', function (): void {
    it('requires activity read authorization on the gateway', function (): void {
        createTestGatewayNode([
            'name' => 'gateway-1',
            'host' => '10.6.0.2',
            'wireguard_address' => '10.6.0.2',
        ]);

        Node::factory()->create([
            'name' => 'control-1',
            'host' => ACTIVITY_LIST_CALLER_WG_IP,
            'wireguard_address' => ACTIVITY_LIST_CALLER_WG_IP,
            'status' => 'active',
        ]);

        $response = $this->call('GET', '/api/activity', [], [], [], ['REMOTE_ADDR' => ACTIVITY_LIST_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.missing_permission', 'activity:read')
            ->assertJsonPath('error.meta.serving_node', 'gateway-1');
    });

    it('lists destructive activity newest first with normalized metadata', function (): void {
        $caller = createActivityListCallerNode();
        $appNode = Node::factory()->appDev()->create(['name' => 'app-1']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);

        createActivityEntry('node.listed', 'read', $caller);
        $olderDestructive = createActivityEntry('node.revoked', 'destructive', $caller, $appNode, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
        $newerDestructive = createActivityEntry('app.removed', 'destructive', $caller, $app, 'bbbbbbbb-cccc-dddd-eeee-ffffffffffff');
        createActivityEntry('node.granted', 'write', $caller, $appNode);

        $olderDestructive->forceFill(['created_at' => now()->subMinute(), 'updated_at' => now()->subMinute()])->save();
        $newerDestructive->forceFill(['created_at' => now(), 'updated_at' => now()])->save();

        $response = $this->call('GET', '/api/activity?effect=destructive&limit=25', [], [], [], ['REMOTE_ADDR' => ACTIVITY_LIST_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.meta.filters.effect', 'destructive')
            ->assertJsonPath('success.meta.limit', 25)
            ->assertJsonPath('success.meta.count', 2)
            ->assertJsonPath('success.meta.has_more', false);

        $activities = $response->json('success.data.activities');

        expect(array_column($activities, 'id'))->toBe([$newerDestructive->id, $olderDestructive->id]);
        expect($activities[0]['type'])->toBe('app.removed');
        expect($activities[0]['effect'])->toBe('destructive');
        expect($activities[0]['subject'])->toBe(['type' => 'app', 'name' => 'docs']);
        expect($activities[0]['actor'])->toBe(['node' => 'caller']);
        expect($activities[0]['command'])->toBe('app:removed');
        expect($activities[0]['summary'])->toBe('Recorded app.removed');
    });

    it('reports has_more when more visible activity matches the requested limit', function (): void {
        $caller = createActivityListCallerNode();

        createActivityEntry('node.removed', 'destructive', $caller);
        createActivityEntry('node.revoked', 'destructive', $caller);
        createActivityEntry('app.removed', 'destructive', $caller);

        $response = $this->call('GET', '/api/activity?effect=destructive&limit=2', [], [], [], ['REMOTE_ADDR' => ACTIVITY_LIST_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonCount(2, 'success.data.activities')
            ->assertJsonPath('success.meta.count', 2)
            ->assertJsonPath('success.meta.has_more', true);
    });

    it('validates filters before reading activity history', function (string $query, string $field, string $reason): void {
        createActivityListCallerNode();

        $response = $this->call('GET', "/api/activity?{$query}", [], [], [], ['REMOTE_ADDR' => ACTIVITY_LIST_CALLER_WG_IP]);

        $response->assertStatus(400)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.message', 'Invalid activity filter.')
            ->assertJsonPath('error.meta.field', $field)
            ->assertJsonPath('error.meta.reason', $reason);
    })->with([
        'effect' => ['effect=nope', 'effect', 'unsupported_value'],
        'limit low' => ['limit=0', 'limit', 'out_of_range'],
        'limit high' => ['limit=201', 'limit', 'out_of_range'],
        'correlation' => ['correlation=not-a-uuid', 'correlation', 'invalid'],
    ]);

    it('logs the activity list read with filter metadata', function (): void {
        $caller = createActivityListCallerNode();
        createActivityEntry('node.removed', 'destructive', $caller);

        $response = $this->call('GET', '/api/activity?effect=destructive&limit=10', [], [], [], ['REMOTE_ADDR' => ACTIVITY_LIST_CALLER_WG_IP]);

        $response->assertOk();

        $entry = Activity::query()
            ->where('event', 'activity.listed')
            ->first();

        expect($entry)->not->toBeNull();
        expect($entry->properties->get('type'))->toBe('read');
        expect($entry->properties->get('filter_effect'))->toBe('destructive');
        expect($entry->properties->get('filter_limit'))->toBe(10);
        expect($entry->properties->get('result_count'))->toBe(1);
    });
});
