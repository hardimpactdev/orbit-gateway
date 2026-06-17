<?php

declare(strict_types=1);

namespace App\E2E\Support;

final readonly class E2ENetwork
{
    public static function assignWireGuardIp(E2EInstance $instance, string $wireGuardIp): void
    {
        E2ECommand::exec(
            $instance,
            sprintf(
                'iface="$(ip -o -4 route show default | awk \'{print $5; exit}\')"; test -n "$iface"; for stale in 10.6.0.2/16 10.6.0.3/16 10.6.0.4/16 10.6.0.5/16 %s; do ip addr del "$stale" dev "$iface" 2>/dev/null || true; done; ip addr add %s dev "$iface" 2>/dev/null || true; ip link set "$iface" up',
                escapeshellarg("{$wireGuardIp}/16"),
                escapeshellarg("{$wireGuardIp}/16"),
            ),
            "Could not assign {$wireGuardIp} to {$instance->name()}",
        );
    }

    public static function routeWireGuardPeer(E2EInstance $instance, string $wireGuardIp, string $providerIp, string $sourceWireGuardIp): void
    {
        E2ECommand::exec(
            $instance,
            sprintf(
                'iface="$(ip -o -4 route show default | awk \'{print $5; exit}\')"; test -n "$iface"; if ! ip -o -4 addr show | awk \'{print $4}\' | cut -d/ -f1 | grep -Fxq %s; then ip addr add %s dev "$iface" 2>/dev/null || true; fi; ip route replace %s via %s dev "$iface" src %s',
                escapeshellarg($sourceWireGuardIp),
                escapeshellarg("{$sourceWireGuardIp}/32"),
                escapeshellarg("{$wireGuardIp}/32"),
                escapeshellarg($providerIp),
                escapeshellarg($sourceWireGuardIp),
            ),
            "Could not route {$wireGuardIp} via {$providerIp} from {$instance->name()}",
        );
    }
}
