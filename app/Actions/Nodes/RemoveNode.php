<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Http\Gateway\Responses\Nodes\NodeRemoveResponse;
use App\Models\Node;
use App\Models\NodeAccess;
use App\Models\WireGuardPeer;
use App\Services\Dns\DnsmasqReconciler;
use App\Services\Nodes\DevelopmentDnsMappingEnactor;

final readonly class RemoveNode
{
    public const string DevelopmentDnsWarningCode = 'node.role_baseline_mismatch';

    public function __construct(
        private DevelopmentDnsMappingEnactor $developmentDnsMappingEnactor,
        private DnsmasqReconciler $dnsmasqReconciler,
    ) {}

    public function handle(Node $node, bool $removedSelf): NodeRemoveResponse
    {
        $name = (string) $node->name;
        $warnings = [];

        $grantsRemoved = NodeAccess::query()
            ->where('consumer_node_id', $node->id)
            ->orWhere('serving_node_id', $node->id)
            ->delete();

        $wireguardPeerRemoved = WireGuardPeer::query()
            ->where('node_id', $node->id)
            ->delete() > 0;

        if ($this->developmentDnsMappingEnactor->mappingFor($node) !== null) {
            $dnsResult = $this->developmentDnsMappingEnactor->remove($node);

            if (($dnsResult['status'] ?? null) === 'failed') {
                $warnings[] = $this->developmentDnsWarning($dnsResult);
            }
        }

        $node->delete();

        $this->dnsmasqReconciler->reconcile();

        return new NodeRemoveResponse(
            name: $name,
            removed: true,
            removedSelf: $removedSelf,
            wireguardPeerRemoved: $wireguardPeerRemoved,
            grantsRemoved: $grantsRemoved,
            warnings: $warnings,
        );
    }

    /**
     * @param  array<string, mixed>  $dnsResult
     * @return array{code: string, message: string, family: string, next_command: string}
     */
    private function developmentDnsWarning(array $dnsResult): array
    {
        $reason = is_string($dnsResult['reason'] ?? null) && $dnsResult['reason'] !== ''
            ? ': '.$dnsResult['reason']
            : '';

        return [
            'code' => self::DevelopmentDnsWarningCode,
            'message' => 'Development DNS mapping could not be removed'.$reason,
            'family' => 'node',
            'next_command' => 'doctor --family=node --restore',
        ];
    }
}
