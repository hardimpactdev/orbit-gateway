<?php

declare(strict_types=1);

use App\Concerns\LogsCommandActivity;
use App\Contracts\Loggable;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

it('uses the active gateway role assignment as the local CLI actor', function (): void {

    $gateway = Node::factory()->create([
        'name' => 'assigned-gateway',
        'status' => 'active',
        'wireguard_address' => '10.6.0.2',
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $gateway->id,
        'role' => 'gateway',
        'status' => 'active',
    ]);

    $command = new class extends Command implements Loggable
    {
        use LogsCommandActivity;

        protected $name = 'activity:test';

        public function writeForTest(): void
        {
            $this->bootActivityLog();
            $this->finalizeActivityLog();
        }
    };

    $command->writeForTest();

    $entry = Activity::query()->first();

    expect($entry)->not->toBeNull();
    expect($entry->causer_id)->toBe($gateway->id);
    expect($entry->properties->get('actor_name'))->toBe('assigned-gateway');
    expect($entry->properties->get('actor_wg_ip'))->toBe('10.6.0.2');
    expect($entry->properties->get('served_by_name'))->toBe('assigned-gateway');
    expect($entry->properties->get('served_by_wg_ip'))->toBe('10.6.0.2');
});
