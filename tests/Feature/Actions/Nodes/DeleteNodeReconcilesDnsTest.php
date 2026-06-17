<?php

declare(strict_types=1);

use App\Actions\Nodes\RemoveNode;
use App\Models\Node;
use App\Services\Dns\DnsmasqReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->reconciler = new class extends DnsmasqReconciler
    {
        public int $reconciles = 0;

        public function __construct() {}

        public function reconcile(): void
        {
            $this->reconciles++;
        }
    };

    app()->instance(DnsmasqReconciler::class, $this->reconciler);
});

it('reconciles dnsmasq after deleting a node', function (): void {
    $node = Node::factory()->create([
        'name' => 'app-1',

        'tld' => 'app-1.test',
        'wireguard_address' => '10.6.0.3',
    ]);

    app(RemoveNode::class)->handle($node, removedSelf: false);

    expect($this->reconciler->reconciles)->toBe(1);
});
