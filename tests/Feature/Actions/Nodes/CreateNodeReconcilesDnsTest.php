<?php

declare(strict_types=1);

use App\Services\Dns\DnsmasqReconciler;
use App\Services\Nodes\NodeRegistryWriter;
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

it('reconciles dnsmasq when writing an app node', function (): void {
    app(NodeRegistryWriter::class)->writeAppNode(
        name: 'app-1',
        tld: 'app-1.test',
        host: '10.6.0.3',
        wireguardAddress: '10.6.0.3',
        gatewayEndpoint: null,
        sshUser: 'orbit',
        user: 'orbit',
    );

    expect($this->reconciler->reconciles)->toBe(1);
});
