<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\NodeRoleAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

const ACTIVITY_SHOW_CALLER_WG_IP = '10.6.0.98';

function createActivityShowCallerNode(): Node
{
    $node = Node::factory()->create([
        'name' => 'caller',
        'host' => ACTIVITY_SHOW_CALLER_WG_IP,
        'wireguard_address' => ACTIVITY_SHOW_CALLER_WG_IP,
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'gateway',
        'status' => 'active',
    ]);

    return $node;
}

function createShowActivityEntry(string $type, string $effect, Node $actor, ?string $correlation = null): Activity
{
    $activity = activity('api')
        ->causedBy($actor)
        ->event($type)
        ->withProperties([
            'type' => $effect,
            'command' => str_replace('.', ':', $type),
            'node' => $actor->name,
        ])
        ->log("Recorded {$type}");

    if ($correlation !== null) {
        $activity->forceFill(['batch_uuid' => $correlation])->save();
    }

    return $activity;
}

describe('ActivityShowController', function (): void {
    it('requires activity read authorization on the gateway', function (): void {
        $gateway = createTestGatewayNode([
            'name' => 'gateway-1',
            'host' => '10.6.0.2',
            'wireguard_address' => '10.6.0.2',
        ]);

        Node::factory()->create([
            'name' => 'control-1',
            'host' => ACTIVITY_SHOW_CALLER_WG_IP,
            'wireguard_address' => ACTIVITY_SHOW_CALLER_WG_IP,
            'status' => 'active',
        ]);

        $activity = createShowActivityEntry('node.created', 'write', $gateway);

        $response = $this->call('GET', "/api/activity/{$activity->id}", [], [], [], ['REMOTE_ADDR' => ACTIVITY_SHOW_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.missing_permission', 'activity:read')
            ->assertJsonPath('error.meta.serving_node', 'gateway-1');
    });

    it('shows one activity with details and related entries ordered oldest first', function (): void {
        $caller = createActivityShowCallerNode();
        $correlation = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

        $firstRelated = createShowActivityEntry('node.create_requested', 'read', $caller, $correlation);
        $selected = createShowActivityEntry('node.created', 'write', $caller, $correlation);
        $secondRelated = createShowActivityEntry('node.updated', 'write', $caller, $correlation);
        createShowActivityEntry('node.removed', 'destructive', $caller);

        $firstRelated->forceFill(['created_at' => now()->subMinutes(2), 'updated_at' => now()->subMinutes(2)])->save();
        $selected->forceFill(['created_at' => now()->subMinute(), 'updated_at' => now()->subMinute()])->save();
        $secondRelated->forceFill(['created_at' => now(), 'updated_at' => now()])->save();

        $response = $this->call('GET', "/api/activity/{$selected->id}", [], [], [], ['REMOTE_ADDR' => ACTIVITY_SHOW_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.activity.id', $selected->id)
            ->assertJsonPath('success.data.activity.type', 'node.created')
            ->assertJsonPath('success.data.activity.effect', 'write')
            ->assertJsonPath('success.data.activity.actor', ['node' => 'caller'])
            ->assertJsonPath('success.data.activity.command', 'node:created')
            ->assertJsonPath('success.data.activity.details.node', 'caller')
            ->assertJsonPath('success.meta.related_count', 2);

        $related = $response->json('success.data.related');

        expect(array_column($related, 'id'))->toBe([$firstRelated->id, $secondRelated->id]);
        expect($related[0])->toMatchArray([
            'type' => 'node.create_requested',
            'effect' => 'read',
        ]);
    });

    it('returns not found and logs the outcome when the activity is missing', function (): void {
        createActivityShowCallerNode();

        $response = $this->call('GET', '/api/activity/999', [], [], [], ['REMOTE_ADDR' => ACTIVITY_SHOW_CALLER_WG_IP]);

        $response->assertNotFound()
            ->assertJsonPath('error.code', 'activity_not_found')
            ->assertJsonPath('error.meta.id', 999);

        $entry = Activity::query()
            ->where('event', 'activity.shown')
            ->first();

        expect($entry)->not->toBeNull();
        expect($entry->properties->get('type'))->toBe('read');
        expect($entry->properties->get('activity_id'))->toBe(999);
        expect($entry->properties->get('related_count'))->toBe(0);
        expect($entry->properties->get('outcome'))->toBe('not_found');
    });

    it('validates activity ids', function (): void {
        createActivityShowCallerNode();

        $response = $this->call('GET', '/api/activity/not-an-id', [], [], [], ['REMOTE_ADDR' => ACTIVITY_SHOW_CALLER_WG_IP]);

        $response->assertStatus(400)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'id')
            ->assertJsonPath('error.meta.reason', 'invalid');
    });
});
