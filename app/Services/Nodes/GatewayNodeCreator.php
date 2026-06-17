<?php

declare(strict_types=1);

namespace App\Services\Nodes;

use App\Contracts\RemoteShell;
use App\Data\Nodes\NodeIdentityArtifact;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\AdoptAction;
use App\Enums\Nodes\NodeConvergenceContext;
use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeRoleStatus;
use App\Enums\Nodes\NodeStatus;
use App\Models\LocalGatewaySettings;
use App\Models\Node;
use App\Models\NodeAccess;
use App\Models\NodeRoleAssignment;
use App\Models\WireGuardPeer;
use App\Services\Nodes\Access\NodePermissionNormalizer;
use App\Services\Nodes\Access\NodePermissionPresets;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Nodes\Roles\NodeRoleAssignmentService;
use App\Services\Nodes\Roles\RoleSelfGrantMaterializer;
use App\Services\OrbitHostInstaller;
use App\Services\RemoteShell\Exceptions\HostKeyMismatch;
use App\Services\RemoteShell\Exceptions\HostKeyPinningFailed;
use App\Services\RemoteShell\SshCommandBuilder;
use App\Services\Security\PublicSshDenyInstaller;
use App\Services\Security\SecurityInstaller;
use App\Services\Security\SshdHardenedInstaller;
use App\Services\Security\SshHostKeyPinner;
use App\Services\Support\GatewayActionResult;
use App\Services\Tools\ToolCatalog;
use App\Services\Tools\ToolInstaller;
use App\Services\Tools\ToolRegistryFailure;
use App\Services\Vpn\VpnDnsSwarmInstaller;
use App\Services\Vpn\WgEasyAddressReservationProbe;
use App\Services\WireGuard\WireGuardKeyGenerator;
use App\Services\WireGuard\WireGuardPeerRealityProbe;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

final class GatewayNodeCreator
{
    private const string DEFAULT_RUNTIME_USER = 'orbit';

    private const int SUCCESS = 0;

    private const int FAILURE = 1;

    /** @var array<string, mixed> */
    private array $arguments = [];

    private ?string $output = null;

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function create(array $arguments): GatewayActionResult
    {
        $this->arguments = $arguments;
        $this->output = null;

        $exitCode = $this->handle(
            app(OrbitHostInstaller::class),
            app(NodeRegistryWriter::class),
            app(NodeRoleAssignmentService::class),
            app(WireGuardKeyGenerator::class),
            app(NodesProbe::class),
            app(NodeConverger::class),
        );

        return GatewayActionResult::fromJsonOutput($exitCode, $this->output);
    }

    private function handle(
        OrbitHostInstaller $installer,
        NodeRegistryWriter $registryWriter,
        NodeRoleAssignmentService $nodeRoleAssignmentService,
        WireGuardKeyGenerator $wireGuardKeyGenerator,
        NodesProbe $nodesProbe,
        NodeConverger $nodeConverger,
    ): int {
        $name = $this->resolveName();

        try {
            $requestedRoles = app(NodeCreationRoleResolver::class)->resolve(
                template: $this->stringOption('template'),
                operator: (bool) $this->option('operator'),
                roles: $this->stringOption('roles'),
            );
        } catch (NodeCreationRoleInputException $exception) {
            return $this->failCommand(
                code: $exception->errorCode,
                message: $exception->getMessage(),
                meta: $exception->meta,
            );
        }

        if ($name === null) {
            return $this->validationFailed('name', 'Node name is required.');
        }

        if (! $this->isValidNodeName($name)) {
            return $this->validationFailed('name', 'Node name must be a valid node name.');
        }

        if (is_int($requestedRoles)) {
            return $requestedRoles;
        }

        if ($this->arrayOption('agent-tool') !== [] && ! in_array(NodeRoleName::Agent->value, $requestedRoles->hosted, true)) {
            return $this->failCommand(
                code: 'validation_failed',
                message: 'Agent tools can only be specified for agent nodes.',
                meta: ['field' => 'agent-tool'],
            );
        }

        $gatewayConfigured = $this->gatewayConfigured();

        if ($requestedRoles->hosted !== []) {
            $inputs = $this->resolveHostedRoleInputs($requestedRoles->hosted);

            if (is_int($inputs)) {
                return $inputs;
            }

            $placement = $this->resolveIngressPlacement(
                $requestedRoles->hosted,
                validateLocalIngressRegistry: true,
            );

            if (is_int($placement)) {
                return $placement;
            }

            if (! $gatewayConfigured) {
                return $this->failCommand(
                    code: 'gateway_unavailable',
                    message: 'Gateway connection is required before creating workload nodes.',
                    meta: ['requested_role' => $requestedRoles->requestedRoleMeta],
                );
            }

            if ($this->containsAppHostingRole($placement['roles'])) {
                return $this->provisionAppNode(
                    installer: $installer,
                    registryWriter: $registryWriter,
                    nodesProbe: $nodesProbe,
                    roleAssignmentService: $nodeRoleAssignmentService,
                    wireGuardKeyGenerator: $wireGuardKeyGenerator,
                    nodeConverger: $nodeConverger,
                    name: $name,
                    inputs: [
                        'host' => $inputs['host'],
                        'tld' => $inputs['tld'],
                        'sshUser' => $inputs['sshUser'] ?? 'root',
                        'gatewayEndpoint' => $inputs['gatewayEndpoint'],
                        'hostKeyFingerprint' => $inputs['hostKeyFingerprint'],
                    ],
                    initialHostedRoles: $placement['roles'],
                    appProductionIngressNodeId: $placement['ingress_node_id'],
                );
            }

            return $this->provisionHostedRoleNode(
                installer: $installer,
                registryWriter: $registryWriter,
                roleAssignmentService: $nodeRoleAssignmentService,
                wireGuardKeyGenerator: $wireGuardKeyGenerator,
                name: $name,
                roles: $placement['roles'],
                inputs: $inputs,
                appProductionIngressNodeId: $placement['ingress_node_id'],
            );
        }

        if ($requestedRoles->clientIdentity || $requestedRoles->operator) {
            $forbiddenInput = $this->forbiddenClientIdentityInput();

            if ($forbiddenInput !== null) {
                return $this->validationFailed($forbiddenInput, 'Client identities do not use workload or SSH/bootstrap-only input.');
            }

            return $this->enrollClientNode($wireGuardKeyGenerator, $name, $requestedRoles->operator);
        }

        if ($requestedRoles->gateway) {
            return $this->convergeGatewayLocally($name);
        }

        return $this->failCommand(
            code: 'gateway_unavailable',
            message: 'Gateway connection is required before creating nodes.',
            meta: ['requested_role' => $requestedRoles->requestedRoleMeta],
        );
    }

    /**
     * @param  list<string>  $roles
     * @param  array{host: string, tld: ?string, sshUser: ?string, gatewayEndpoint: ?string, hostKeyFingerprint: ?string}  $inputs
     */
    private function provisionHostedRoleNode(
        OrbitHostInstaller $installer,
        NodeRegistryWriter $registryWriter,
        NodeRoleAssignmentService $roleAssignmentService,
        WireGuardKeyGenerator $wireGuardKeyGenerator,
        string $name,
        array $roles,
        array $inputs,
        ?int $appProductionIngressNodeId = null,
    ): int {
        $existing = Node::query()->where('name', $name)->first();

        if ($existing instanceof Node && $existing->isActive()) {
            return $this->failCommand(
                code: 'node.incompatible',
                message: "Node '{$name}' already exists.",
                meta: ['name' => $name],
            );
        }

        if ($inputs['tld'] !== null && Node::query()->where('tld', $inputs['tld'])->where('status', NodeStatus::Active->value)->exists()) {
            return $this->failCommand(
                code: 'node.incompatible',
                message: "Development TLD '{$inputs['tld']}' is already assigned to another node.",
                meta: [
                    'field' => 'tld',
                    'value' => $inputs['tld'],
                ],
            );
        }

        $requiresHostProvisioning = array_intersect($roles, [
            NodeRoleName::AppDevelopment->value,
            NodeRoleName::AppProduction->value,
            NodeRoleName::Database->value,
            NodeRoleName::Ingress->value,
            NodeRoleName::Agent->value,
        ]) !== [];

        $preflight = $this->preflightAgentSetup($roles);
        if (is_int($preflight)) {
            return $preflight;
        }

        $runtimeUser = self::DEFAULT_RUNTIME_USER;
        $wireguardAddress = $this->resolveProvisionedNodeWireguardAddress();

        if (is_int($wireguardAddress)) {
            return $wireguardAddress;
        }

        $gatewayEndpoint = $inputs['gatewayEndpoint'] ?? $this->gatewayEndpoint();
        $platform = 'ubuntu';
        $host = $requiresHostProvisioning ? $inputs['host'] : '';
        $user = $requiresHostProvisioning ? $runtimeUser : self::DEFAULT_RUNTIME_USER;
        $orbitPath = "/home/{$runtimeUser}/orbit";
        $node = null;

        if ($requiresHostProvisioning) {
            $sshUser = $inputs['sshUser'] ?? 'root';

            try {
                $pinnedHostKey = app(SshHostKeyPinner::class)->pin($inputs['host'], $inputs['hostKeyFingerprint']);
            } catch (HostKeyMismatch $exception) {
                return $this->failCommand(
                    code: 'node.host_key_mismatch',
                    message: $exception->getMessage(),
                    meta: ['host' => $inputs['host']],
                );
            } catch (HostKeyPinningFailed $exception) {
                return $this->failCommand(
                    code: 'node.host_key_pin_failed',
                    message: $exception->getMessage(),
                    meta: ['host' => $inputs['host']],
                );
            }

            $node = $registryWriter->writeNodeIdentity(
                name: $name,
                tld: $inputs['tld'],
                platform: $platform,
                host: $host,
                wireguardAddress: $wireguardAddress,
                gatewayEndpoint: $gatewayEndpoint,
                user: $user,
                orbitPath: $orbitPath,
                status: NodeStatus::Provisioning,
                hostKey: $pinnedHostKey,
            );

            $installer->usePinnedNode($node);
            $installation = $installer->install($inputs['host'], $sshUser, $runtimeUser);

            if (! $installation->successful) {
                $failure = $this->installerFailure(
                    role: $this->firstRole($roles),
                    host: $inputs['host'],
                    sshUser: $sshUser,
                    errorOutput: $installation->errorOutput,
                );

                $this->rollbackProvisioningNode($node, 'host_installer_failed', [
                    'host' => $inputs['host'],
                    'step' => 'host_install',
                    'error' => trim($installation->errorOutput) ?: null,
                ]);

                return $failure;
            }

            $sshAuthorization = $this->authorizeRuntimeSshUser($node, $runtimeUser, $runtimeUser);

            if (is_int($sshAuthorization)) {
                $this->rollbackProvisioningNode($node, 'runtime_ssh_authorization_failed', [
                    'host' => $inputs['host'],
                    'step' => 'steady_state_ssh_authorization',
                ]);

                return $sshAuthorization;
            }

            $sshHardening = $this->hardenRuntimeSshAccess($node, $runtimeUser);

            if (is_int($sshHardening)) {
                $this->rollbackProvisioningNode($node, 'ssh_hardening_failed', [
                    'host' => $inputs['host'],
                    'step' => 'ssh_hardening',
                ]);

                return $sshHardening;
            }

            $wireGuardProvisioning = $this->configureProvisionedNodeWireGuard(
                $node,
                $wireGuardKeyGenerator,
                gatewayEndpointOverride: $inputs['gatewayEndpoint'],
            );

            if (is_int($wireGuardProvisioning)) {
                $this->rollbackProvisioningNode($node, 'wireguard_install_failed', [
                    'host' => $inputs['host'],
                    'step' => 'node_wireguard_install',
                ]);

                return $wireGuardProvisioning;
            }
        }

        if (! $node instanceof Node) {
            $node = $registryWriter->writeNodeIdentity(
                name: $name,
                tld: $inputs['tld'],
                platform: $platform,
                host: $host,
                wireguardAddress: $wireguardAddress,
                gatewayEndpoint: $gatewayEndpoint,
                user: $user,
                orbitPath: $orbitPath,
            );
        }

        $wireGuardPeerFailure = $this->ensureAgentWireGuardPeer($node, $roles, $wireGuardKeyGenerator);

        if (is_int($wireGuardPeerFailure)) {
            if ($requiresHostProvisioning) {
                $this->rollbackProvisioningNode($node, 'wireguard_peer_failed', [
                    'host' => $inputs['host'],
                    'step' => 'wireguard_identity',
                ]);
            }

            return $wireGuardPeerFailure;
        }

        $failedAssignment = null;

        foreach ($this->orderHostedRoles($roles) as $role) {
            $settings = $role === NodeRoleName::AppProduction->value
                ? ['ingress_node_id' => $appProductionIngressNodeId ?? $node->id]
                : $this->settingsForRole($role, $inputs['tld']);

            $assignment = $roleAssignmentService->addDuringCreation($node, $role, $settings);

            if ($assignment->status !== NodeRoleStatus::Error) {
                continue;
            }

            $failedAssignment = $assignment;

            break;
        }

        if ($failedAssignment instanceof NodeRoleAssignment) {
            $failure = $this->failCommand(
                code: 'node.provisioning_incomplete',
                message: "Node '{$name}' created but hosted role '{$failedAssignment->role}' failed to converge.",
                meta: [
                    'node' => $name,
                    'role' => $failedAssignment->role,
                    'status' => $failedAssignment->status->value,
                    'settings' => $failedAssignment->settings ?? [],
                    'last_error' => $failedAssignment->last_error,
                ],
            );

            if ($requiresHostProvisioning) {
                $this->rollbackProvisioningNode($node, 'role_assignment_failed', [
                    'host' => $inputs['host'],
                    'role' => $failedAssignment->role,
                    'step' => 'role_assignment',
                    'error' => $failedAssignment->last_error,
                ]);
            }

            return $failure;
        }

        $warnings = [];

        if (in_array(NodeRoleName::Agent->value, $roles, true)) {
            $selfGrantResult = $this->setupAgentSelfGrant($node);
            if (is_int($selfGrantResult)) {
                $this->rollbackProvisioningNode($node, 'agent_self_grant_failed', [
                    'host' => $inputs['host'],
                    'step' => 'agent_self_grant',
                ]);

                return $selfGrantResult;
            }

            $grantToResult = $this->setupGrantTo($node);
            if (is_int($grantToResult)) {
                $this->rollbackProvisioningNode($node, 'agent_grant_to_failed', [
                    'host' => $inputs['host'],
                    'step' => 'agent_grant_to',
                ]);

                return $grantToResult;
            }

            $grantFromResult = $this->setupGrantFrom($node);
            if (is_int($grantFromResult)) {
                $this->rollbackProvisioningNode($node, 'agent_grant_from_failed', [
                    'host' => $inputs['host'],
                    'step' => 'agent_grant_from',
                ]);

                return $grantFromResult;
            }

            $agentToolResult = $this->setupAgentTools($node, $warnings);
            if (is_int($agentToolResult)) {
                $this->rollbackProvisioningNode($node, 'agent_tool_failed', [
                    'host' => $inputs['host'],
                    'step' => 'agent_tool',
                ]);

                return $agentToolResult;
            }
        }

        if ($requiresHostProvisioning) {
            $securityBaseline = $this->finalizeNodeSecurityBaseline($node);

            if (is_int($securityBaseline)) {
                $this->rollbackProvisioningNode($node, 'security_baseline_failed', [
                    'host' => $inputs['host'],
                    'step' => 'security_baseline',
                ]);

                return $securityBaseline;
            }

            $registryWriter->markActive($node);
            $node->refresh();
        }

        $payload = [
            'result' => [
                'action' => 'created',
            ],
            'node' => [
                'name' => $name,
                'tld' => $node->tld,
                'platform' => $node->platform,
                'addresses' => [
                    'wireguard' => $wireguardAddress,
                ],
                'status' => 'active',
            ],
            'roles' => $node->fresh()->roleAssignments->map(fn (NodeRoleAssignment $assignment): array => [
                'role' => $assignment->role,
                'status' => $assignment->status->value,
                'settings' => $assignment->settings ?? [],
                'last_error' => $assignment->last_error,
            ])->values()->all(),
            'provisioning' => [
                'transport' => $requiresHostProvisioning ? 'ssh' : 'none',
                'host' => $requiresHostProvisioning ? $inputs['host'] : null,
                'status' => $requiresHostProvisioning ? 'complete' : 'created',
            ],
            'next_steps' => [],
        ];

        if (in_array(NodeRoleName::AppDevelopment->value, $roles, true)) {
            $payload['development_tld'] = [
                'tld' => $inputs['tld'],
                'gateway_dns' => [
                    'domain' => "*.{$inputs['tld']}",
                    'target' => $wireguardAddress,
                    'status' => 'configured',
                ],
            ];
        }

        if ($this->wantsJson()) {
            return $this->jsonSuccess(
                $payload,
                $warnings !== [] ? ['warnings' => $warnings] : [],
            );
        }

        $this->info("Created node {$name}.");

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $roles
     */
    private function ensureAgentWireGuardPeer(Node $node, array $roles, WireGuardKeyGenerator $wireGuardKeyGenerator): ?int
    {
        if (! in_array(NodeRoleName::Agent->value, $roles, true)) {
            return null;
        }

        if (WireGuardPeer::query()->where('node_id', $node->id)->exists()) {
            return null;
        }

        $wireGuardAddress = is_string($node->wireguard_address) ? trim($node->wireguard_address) : '';

        if ($wireGuardAddress === '') {
            return $this->failCommand(
                code: 'node.provisioning_incomplete',
                message: "Node '{$node->name}' created but agent WireGuard identity could not be stored.",
                meta: [
                    'node' => $node->name,
                    'step' => 'wireguard_identity',
                    'error' => 'Node WireGuard address is missing.',
                ],
            );
        }

        try {
            $keys = $wireGuardKeyGenerator->generateKeyPair();
        } catch (RuntimeException $exception) {
            return $this->failCommand(
                code: 'node.provisioning_incomplete',
                message: 'Failed to generate WireGuard identity material.',
                meta: [
                    'node' => $node->name,
                    'step' => 'wireguard_identity',
                    'error' => $exception->getMessage(),
                ],
            );
        }

        WireGuardPeer::query()->create([
            'node_id' => $node->id,
            'public_key' => $keys['public_key'],
            'private_key' => $keys['private_key'],
            'allowed_ips' => "{$wireGuardAddress}/32",
        ]);

        return null;
    }

    private function configureProvisionedNodeWireGuard(
        Node $node,
        WireGuardKeyGenerator $wireGuardKeyGenerator,
        ?string $gatewayEndpointOverride = null,
    ): ?int {
        $wireguardAddress = is_string($node->wireguard_address) ? trim($node->wireguard_address) : '';

        if ($wireguardAddress === '') {
            return $this->failCommand(
                code: 'node.provisioning_incomplete',
                message: "Node '{$node->name}' created but WireGuard could not be configured.",
                meta: [
                    'node' => $node->name,
                    'step' => 'wireguard_identity',
                    'error' => 'Node WireGuard address is missing.',
                ],
            );
        }

        $gateway = $this->gatewayQuery()->first();

        if (! $gateway instanceof Node) {
            return $this->failCommand(
                code: 'node.provisioning_incomplete',
                message: 'Gateway identity is missing locally.',
                meta: [
                    'node' => $node->name,
                    'step' => 'gateway_identity',
                    'error' => 'No active gateway node record exists.',
                ],
            );
        }

        $gatewayEndpoint = $gatewayEndpointOverride ?? $this->gatewayPublicEndpoint($gateway);

        if ($gatewayEndpoint === null) {
            return $this->failCommand(
                code: 'node.provisioning_incomplete',
                message: 'Gateway public WireGuard endpoint is missing locally.',
                meta: [
                    'node' => $gateway->name,
                    'step' => 'gateway_wireguard_endpoint',
                ],
            );
        }

        $peer = $this->ensureProvisionedNodeWireGuardPeer($node, $wireGuardKeyGenerator, $wireguardAddress);

        if (is_int($peer)) {
            return $peer;
        }

        $wireguardServerPublicKey = $this->configureGatewayWireGuardServerPeer($node, $peer, $wireguardAddress);

        if (is_int($wireguardServerPublicKey)) {
            return $wireguardServerPublicKey;
        }

        $wireguardConfig = $this->controlWireGuardConfig(
            controlPrivateKey: $peer->private_key,
            controlWireguardAddress: $wireguardAddress,
            gatewayPublicKey: $wireguardServerPublicKey,
            gatewayWireguardAddress: (string) $gateway->wireguard_address,
            gatewayEndpoint: $gatewayEndpoint,
            preSharedKey: $peer->pre_shared_key,
            allowedIps: '10.6.0.0/24',
        );

        $nodeWireGuardInstall = $this->installProvisionedNodeWireGuard($node, $wireguardConfig);

        if (is_int($nodeWireGuardInstall)) {
            return $nodeWireGuardInstall;
        }

        return $this->waitForProvisionedNodeWireGuard($node, $wireguardAddress);
    }

    private function ensureProvisionedNodeWireGuardPeer(Node $node, WireGuardKeyGenerator $wireGuardKeyGenerator, string $wireguardAddress): WireGuardPeer|int
    {
        $peer = WireGuardPeer::query()->where('node_id', $node->id)->first();

        if ($peer instanceof WireGuardPeer && $peer->private_key !== '') {
            if (! is_string($peer->pre_shared_key) || $peer->pre_shared_key === '') {
                $peer->pre_shared_key = $this->generatePreSharedKey();
            }

            $peer->allowed_ips = "{$wireguardAddress}/32";
            $peer->save();

            return $peer;
        }

        try {
            $keys = $wireGuardKeyGenerator->generateKeyPair();
            $preSharedKey = $this->generatePreSharedKey();
        } catch (RuntimeException $exception) {
            return $this->failCommand(
                code: 'node.provisioning_incomplete',
                message: 'Failed to generate WireGuard identity material.',
                meta: [
                    'node' => $node->name,
                    'step' => 'wireguard_identity',
                    'error' => $exception->getMessage(),
                ],
            );
        }

        return WireGuardPeer::query()->updateOrCreate(
            ['node_id' => $node->id],
            [
                'public_key' => $keys['public_key'],
                'private_key' => $keys['private_key'],
                'pre_shared_key' => $preSharedKey,
                'allowed_ips' => "{$wireguardAddress}/32",
            ],
        );
    }

    private function configureGatewayWireGuardServerPeer(Node $node, WireGuardPeer $peer, string $wireguardAddress): string|int
    {
        if ($peer->public_key === '' || $peer->pre_shared_key === null || $peer->pre_shared_key === '') {
            return $this->failCommand(
                code: 'node.provisioning_incomplete',
                message: "Node '{$node->name}' created but WireGuard peer material is incomplete.",
                meta: [
                    'node' => $node->name,
                    'step' => 'wireguard_identity',
                ],
            );
        }

        try {
            $installer = app(VpnDnsSwarmInstaller::class);
            $installer->configurePeers([
                [
                    'name' => $node->name,
                    'private_key' => $peer->private_key,
                    'public_key' => $peer->public_key,
                    'pre_shared_key' => $peer->pre_shared_key,
                    'address' => $wireguardAddress,
                ],
            ]);

            return $installer->publicKey();
        } catch (RuntimeException $exception) {
            return $this->failCommand(
                code: 'node.provisioning_incomplete',
                message: "Gateway could not install WireGuard peer for node '{$node->name}'.",
                meta: [
                    'node' => $node->name,
                    'step' => 'gateway_wireguard_peer',
                    'error' => $exception->getMessage(),
                ],
            );
        }
    }

    private function installProvisionedNodeWireGuard(Node $node, string $wireguardConfig): ?int
    {
        $runtimeUser = $node->user ?: self::DEFAULT_RUNTIME_USER;
        $host = (string) $node->host;
        $script = <<<'SH'
set -euo pipefail
CONFIG_FILE="$(mktemp)"
trap 'rm -f "$CONFIG_FILE"' EXIT
cat > "$CONFIG_FILE"
if ! command -v wg >/dev/null 2>&1 || ! command -v wg-quick >/dev/null 2>&1; then
    sudo apt-get -o DPkg::Lock::Timeout=300 update -qq
    sudo DEBIAN_FRONTEND=noninteractive apt-get -o DPkg::Lock::Timeout=300 install -y -qq wireguard wireguard-tools
fi
sudo install -d -m 0700 /etc/wireguard
sudo install -m 0600 -o root -g root "$CONFIG_FILE" /etc/wireguard/wg-orbit.conf
sudo systemctl enable wg-quick@wg-orbit >/dev/null
sudo systemctl restart wg-quick@wg-orbit
SH;

        $result = Process::timeout(240)
            ->input($wireguardConfig)
            ->run($this->ssh(
                user: $runtimeUser,
                host: $host,
                command: $script,
                node: $node,
            ));

        if ($result->successful()) {
            return null;
        }

        return $this->failCommand(
            code: 'node.provisioning_incomplete',
            message: "Host '{$host}' could not install WireGuard.",
            meta: [
                'host' => $host,
                'step' => 'node_wireguard_install',
                'error' => trim($result->errorOutput()."\n".$result->output()) ?: null,
            ],
        );
    }

    private function waitForProvisionedNodeWireGuard(Node $node, string $wireguardAddress): ?int
    {
        $lastOutput = null;

        for ($attempt = 0; $attempt < 12; $attempt++) {
            $probe = $this->probeProvisionedNodeWireGuard($wireguardAddress);

            if ($probe->successful()) {
                return null;
            }

            $lastOutput = trim($probe->errorOutput()."\n".$probe->output()) ?: null;

            $e2e = getenv('ORBIT_E2E');

            if (! app()->runningUnitTests() || (is_string($e2e) && $e2e !== '' && $e2e !== '0')) {
                sleep(2);
            }
        }

        return $this->failCommand(
            code: 'node.provisioning_incomplete',
            message: "Gateway could not reach node '{$node->name}' over WireGuard.",
            meta: [
                'node' => $node->name,
                'step' => 'node_wireguard_reachability',
                'wireguard_address' => $wireguardAddress,
                'error' => $lastOutput,
            ],
        );
    }

    private function probeProvisionedNodeWireGuard(string $wireguardAddress): RemoteShellResult
    {
        $command = sprintf('ping -c 1 -W 2 %s', escapeshellarg($wireguardAddress));

        if ($this->runningInsideOrbitGateway()) {
            $gateway = app(NodeRoleAssignments::class)
                ->activeGatewayNodeQuery()
                ->orderBy('id')
                ->first();

            if (! $gateway instanceof Node) {
                return new RemoteShellResult(
                    exitCode: 1,
                    stdout: '',
                    stderr: 'No active gateway node record exists.',
                    durationMs: 0,
                );
            }

            return app(RemoteShell::class)->run($gateway, $command, ['timeout' => 5]);
        }

        $startedAt = hrtime(true);
        $probe = Process::timeout(5)->run($command);

        return new RemoteShellResult(
            exitCode: $probe->successful() ? 0 : 1,
            stdout: $probe->output(),
            stderr: $probe->errorOutput(),
            durationMs: (int) ((hrtime(true) - $startedAt) / 1_000_000),
        );
    }

    private function runningInsideOrbitGateway(): bool
    {
        $hostPath = getenv('ORBIT_HOST_PATH');

        if (is_string($hostPath) && trim($hostPath) !== '') {
            return true;
        }

        $sourcePath = getenv('ORBIT_SOURCE_PATH');

        return is_string($sourcePath) && trim($sourcePath) === '/opt/orbit';
    }

    private function gatewayPublicEndpoint(Node $gateway): ?string
    {
        $vpnRole = $gateway->roleAssignments()
            ->where('role', NodeRoleName::Vpn->value)
            ->first();

        $settings = $vpnRole?->settings;
        $publicEndpoint = is_array($settings) ? ($settings['public_endpoint'] ?? null) : null;

        if (is_string($publicEndpoint) && $publicEndpoint !== '') {
            return $publicEndpoint;
        }

        foreach ([$gateway->gateway_endpoint, $gateway->host] as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    private function convergeGatewayLocally(string $name): int
    {
        $host = $this->resolveHost('gateway');

        if ($host === null) {
            return $this->validationFailed('host', 'Host is required for gateway nodes.');
        }

        if (! $this->isValidHost($host)) {
            return $this->validationFailed('host', 'Host must be a valid IP address or dotted DNS name.');
        }

        $gateway = $this->gatewayQuery()
            ->where('name', $name)
            ->first();

        if (! $gateway instanceof Node || ! $this->gatewayHostMatches($gateway, $host)) {
            return $this->failCommand(
                code: 'node.incompatible',
                message: 'Existing gateway is incompatible with the requested host or identity.',
                meta: ['name' => $name, 'host' => $host],
            );
        }

        $payload = $this->gatewayConvergencePayload($gateway, $host);

        if ($this->wantsJson()) {
            return $this->jsonSuccess($payload);
        }

        $this->info('Gateway is already provisioned.');

        return self::SUCCESS;
    }

    private function gatewayHostMatches(Node $gateway, string $host): bool
    {
        return $gateway->host === $host || $gateway->gateway_endpoint === $host;
    }

    /**
     * @return array<string, mixed>
     */
    private function gatewayConvergencePayload(Node $gateway, string $host): array
    {
        return [
            'result' => [
                'action' => 'converged',
            ],
            'node' => [
                'name' => $gateway->name,
                'tld' => null,
                'platform' => $gateway->platform ?? 'unknown',
                'addresses' => [
                    'wireguard' => $gateway->wireguard_address,
                    'gateway_endpoint' => $gateway->gateway_endpoint ?? $gateway->host,
                ],
                'status' => 'active',
            ],
            'provisioning' => [
                'transport' => 'none',
                'host' => $host,
                'status' => 'already_provisioned',
            ],
            'next_steps' => [],
        ];
    }

    private function enrollClientNode(WireGuardKeyGenerator $wireGuardKeyGenerator, string $name, bool $operator): int
    {
        $existing = Node::query()->where('name', $name)->first();

        if ($existing instanceof Node && ! $existing->isOperator()) {
            return $this->failCommand(
                code: 'node.incompatible',
                message: "Node '{$name}' already exists with a different role.",
                meta: [
                    'name' => $name,
                    'existing_role' => $existing->displayRole(),
                    'requested_role' => $operator ? 'operator' : 'client',
                ],
            );
        }

        $wireguardAddress = $existing instanceof Node && is_string($existing->wireguard_address) && $existing->wireguard_address !== ''
            ? $existing->wireguard_address
            : $this->nextWireguardAddress();

        $gateway = $this->gatewayQuery()->first();

        if (! $gateway instanceof Node) {
            return $this->failCommand(
                code: 'node.provisioning_incomplete',
                message: 'Gateway identity is missing locally.',
                meta: [
                    'step' => 'gateway_identity',
                    'error' => 'No active gateway node record exists.',
                ],
            );
        }

        $gatewayPeer = WireGuardPeer::query()->where('node_id', $gateway->id)->first();

        if (! $gatewayPeer instanceof WireGuardPeer) {
            return $this->failCommand(
                code: 'node.provisioning_incomplete',
                message: 'Gateway WireGuard peer material is missing locally.',
                meta: [
                    'step' => 'gateway_wireguard_identity',
                    'node' => $gateway->name,
                ],
            );
        }

        try {
            $keys = $wireGuardKeyGenerator->generateKeyPair();
        } catch (RuntimeException $exception) {
            return $this->failCommand(
                code: 'node.provisioning_incomplete',
                message: 'Failed to generate WireGuard identity material.',
                meta: [
                    'node' => $name,
                    'step' => 'wireguard_identity',
                    'error' => $exception->getMessage(),
                ],
            );
        }

        $node = Node::query()->updateOrCreate(
            ['name' => $name],
            [
                'tld' => null,
                'platform' => 'unknown',
                'host' => $wireguardAddress,
                'wireguard_address' => $wireguardAddress,
                'gateway_endpoint' => $this->gatewayEndpoint(),
                'user' => self::DEFAULT_RUNTIME_USER,
                'orbit_path' => '/home/'.self::DEFAULT_RUNTIME_USER.'/orbit',
                'status' => 'active',
            ],
        );

        $peer = WireGuardPeer::query()->updateOrCreate(
            ['node_id' => $node->id],
            [
                'public_key' => $keys['public_key'],
                'private_key' => $keys['private_key'],
                'allowed_ips' => "{$wireguardAddress}/32",
            ],
        );

        $wireguardConfig = $this->controlWireGuardConfig(
            controlPrivateKey: $peer->private_key,
            controlWireguardAddress: $wireguardAddress,
            gatewayPublicKey: $gatewayPeer->public_key,
            gatewayWireguardAddress: (string) $gateway->wireguard_address,
            gatewayEndpoint: $gateway->gateway_endpoint ?? $gateway->host,
        );

        $clientLabel = $operator ? 'operator node' : 'client';

        $payload = [
            'result' => [
                'action' => 'enrolled',
            ],
            'node' => [
                'name' => $name,
                'tld' => null,
                'platform' => 'unknown',
                'addresses' => [
                    'wireguard' => $wireguardAddress,
                ],
                'status' => 'active',
            ],
            'provisioning' => [
                'transport' => 'wireguard',
                'host' => null,
                'status' => 'enrolled',
            ],
            'wireguard' => [
                'config' => $wireguardConfig,
            ],
            'next_steps' => [
                "Install the WireGuard configuration on the {$clientLabel}.",
                'Join the Orbit WireGuard network.',
                "Run `orbit gateway:add` on the {$clientLabel}.",
            ],
        ];

        if ($this->wantsJson()) {
            return $this->jsonSuccess($payload);
        }

        $type = $operator ? 'operator' : 'client';
        $this->info("Enrolled {$type} node {$name}.");

        return self::SUCCESS;
    }

    /**
     * @param  array{host: string, tld: ?string, sshUser: string, gatewayEndpoint: ?string, hostKeyFingerprint: ?string}  $inputs
     * @param  list<string>  $initialHostedRoles
     */
    private function provisionAppNode(
        OrbitHostInstaller $installer,
        NodeRegistryWriter $registryWriter,
        NodesProbe $nodesProbe,
        NodeRoleAssignmentService $roleAssignmentService,
        WireGuardKeyGenerator $wireGuardKeyGenerator,
        NodeConverger $nodeConverger,
        string $name,
        array $inputs,
        array $initialHostedRoles = [],
        ?int $appProductionIngressNodeId = null,
    ): int {
        $existing = Node::query()->where('name', $name)->first();

        if (
            $existing instanceof Node
            && $existing->isActive()
            && app(NodeRoleAssignments::class)->nodeHasActiveAppHostRole($existing)
            && ! WireGuardPeer::query()->where('node_id', $existing->id)->exists()
        ) {
            return $this->adoptExistingAppNode($nodesProbe, $nodeConverger, $existing, $inputs, $roleAssignmentService, $initialHostedRoles, $appProductionIngressNodeId);
        }

        if ($existing instanceof Node && $existing->isActive()) {
            return $this->failCommand(
                code: 'node.incompatible',
                message: "Node '{$name}' already exists.",
                meta: ['name' => $name],
            );
        }

        if ($existing instanceof Node) {
            return $this->adoptExistingAppNode($nodesProbe, $nodeConverger, $existing, $inputs, $roleAssignmentService, $initialHostedRoles, $appProductionIngressNodeId);
        }

        if ($inputs['tld'] !== null && Node::query()->where('tld', $inputs['tld'])->where('status', NodeStatus::Active->value)->exists()) {
            return $this->failCommand(
                code: 'node.incompatible',
                message: "Development TLD '{$inputs['tld']}' is already assigned to another node.",
                meta: [
                    'field' => 'tld',
                    'value' => $inputs['tld'],
                ],
            );
        }

        $adoption = $this->materializeUnknownAppNode($nodesProbe, $registryWriter, $nodeConverger, $name, $inputs, $roleAssignmentService, $initialHostedRoles, $appProductionIngressNodeId);

        if (is_int($adoption)) {
            return $adoption;
        }

        $wireguardAddress = $this->resolveProvisionedNodeWireguardAddress();

        if (is_int($wireguardAddress)) {
            return $wireguardAddress;
        }

        $developmentDnsMappingFailure = $this->guardDevelopmentDnsMappingAvailable($inputs['tld'], $wireguardAddress);

        if (is_int($developmentDnsMappingFailure)) {
            return $developmentDnsMappingFailure;
        }

        $runtimeUser = self::DEFAULT_RUNTIME_USER;
        $gatewayEndpoint = $inputs['gatewayEndpoint'] ?? $this->gatewayEndpoint();

        try {
            $pinnedHostKey = app(SshHostKeyPinner::class)->pin($inputs['host'], $inputs['hostKeyFingerprint']);
        } catch (HostKeyMismatch $exception) {
            return $this->failCommand(
                code: 'node.host_key_mismatch',
                message: $exception->getMessage(),
                meta: ['host' => $inputs['host']],
            );
        } catch (HostKeyPinningFailed $exception) {
            return $this->failCommand(
                code: 'node.host_key_pin_failed',
                message: $exception->getMessage(),
                meta: ['host' => $inputs['host']],
            );
        }

        $node = $registryWriter->writeAppNode(
            name: $name,
            tld: $inputs['tld'],
            host: $inputs['host'],
            wireguardAddress: $wireguardAddress,
            gatewayEndpoint: $gatewayEndpoint,
            sshUser: $inputs['sshUser'],
            user: $runtimeUser,
            status: NodeStatus::Provisioning,
            hostKey: $pinnedHostKey,
        );

        try {
            $installer->usePinnedNode($node);
            $installation = $installer->install($inputs['host'], $inputs['sshUser'], $runtimeUser);

            if (! $installation->successful) {
                $failure = $this->installerFailure(
                    role: $this->firstRole($initialHostedRoles),
                    host: $inputs['host'],
                    sshUser: $inputs['sshUser'],
                    errorOutput: $installation->errorOutput,
                );

                $this->rollbackProvisioningNode($node, 'host_installer_failed', [
                    'host' => $inputs['host'],
                    'step' => 'host_install',
                    'error' => trim($installation->errorOutput) ?: null,
                ]);

                return $failure;
            }

            $sshAuthorization = $this->authorizeRuntimeSshUser($node, $runtimeUser, $runtimeUser);

            if (is_int($sshAuthorization)) {
                $this->rollbackProvisioningNode($node, 'runtime_ssh_authorization_failed', [
                    'host' => $inputs['host'],
                    'step' => 'steady_state_ssh_authorization',
                ]);

                return $sshAuthorization;
            }

            $sshHardening = $this->hardenRuntimeSshAccess($node, $runtimeUser);

            if (is_int($sshHardening)) {
                $this->rollbackProvisioningNode($node, 'ssh_hardening_failed', [
                    'host' => $inputs['host'],
                    'step' => 'ssh_hardening',
                ]);

                return $sshHardening;
            }

            $wireGuardProvisioning = $this->configureProvisionedNodeWireGuard(
                $node,
                $wireGuardKeyGenerator,
                gatewayEndpointOverride: $inputs['gatewayEndpoint'],
            );

            if (is_int($wireGuardProvisioning)) {
                $this->rollbackProvisioningNode($node, 'wireguard_install_failed', [
                    'host' => $inputs['host'],
                    'step' => 'node_wireguard_install',
                ]);

                return $wireGuardProvisioning;
            }

            $roleAssignmentFailure = $this->ensureInitialHostedRoles($node, $roleAssignmentService, $initialHostedRoles, $inputs['tld'], $appProductionIngressNodeId);

            if (is_int($roleAssignmentFailure)) {
                $this->rollbackProvisioningNode($node, 'role_assignment_failed', [
                    'host' => $inputs['host'],
                    'step' => 'role_assignment',
                ]);

                return $roleAssignmentFailure;
            }

            $nodeSetup = $this->setupManagedNode($nodeConverger, $node, $initialHostedRoles);

            if (is_int($nodeSetup)) {
                $this->rollbackProvisioningNode($node, 'node_setup_failed', [
                    'host' => $inputs['host'],
                    'step' => 'node_setup',
                ]);

                return $nodeSetup;
            }

            $securityBaseline = $this->finalizeNodeSecurityBaseline($node);

            if (is_int($securityBaseline)) {
                $this->rollbackProvisioningNode($node, 'security_baseline_failed', [
                    'host' => $inputs['host'],
                    'step' => 'security_baseline',
                ]);

                return $securityBaseline;
            }

            $developmentDns = app(DevelopmentDnsMappingEnactor::class)->converge($node);

            $registryWriter->markActive($node);
            $node->refresh();
        } catch (Throwable $exception) {
            $this->rollbackProvisioningNode($node, 'exception', [
                'host' => $inputs['host'],
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        if ($initialHostedRoles !== [] && ($developmentDns['status'] ?? null) === 'already_configured') {
            $developmentDns['status'] = 'configured';
        }

        $payload = [
            'result' => [
                'action' => 'created',
            ],
            'node' => [
                'name' => $name,
                'tld' => $inputs['tld'],
                'platform' => 'unknown',
                'addresses' => [
                    'wireguard' => $wireguardAddress,
                ],
                'status' => 'active',
            ],
            'provisioning' => [
                'transport' => 'ssh',
                'host' => $inputs['host'],
                'status' => 'complete',
            ],
            'next_steps' => [],
        ];

        if ($this->containsDevelopmentAppRole($initialHostedRoles)) {
            $payload['development_tld'] = [
                'tld' => $inputs['tld'],
                'gateway_dns' => [
                    'domain' => "*.{$inputs['tld']}",
                    'target' => $wireguardAddress,
                    'status' => $developmentDns['status'],
                ],
            ];
        }

        if ($this->wantsJson()) {
            return $this->jsonSuccess($payload);
        }

        $this->info("Created app node {$name}.");
        $this->line("Endpoint: {$inputs['host']}");

        return self::SUCCESS;
    }

    /**
     * @param  array{host: string, tld: ?string, sshUser: string, gatewayEndpoint: ?string, hostKeyFingerprint: ?string}  $inputs
     * @param  list<string>  $initialHostedRoles
     */
    private function materializeUnknownAppNode(
        NodesProbe $nodesProbe,
        NodeRegistryWriter $registryWriter,
        NodeConverger $nodeConverger,
        string $name,
        array $inputs,
        NodeRoleAssignmentService $roleAssignmentService,
        array $initialHostedRoles = [],
        ?int $appProductionIngressNodeId = null,
    ): ?int {
        $candidate = new Node([
            'name' => $name,
            'tld' => $inputs['tld'],
            'platform' => 'unknown',
            'host' => $inputs['host'],
            'wireguard_address' => '',
            'gateway_endpoint' => $inputs['gatewayEndpoint'] ?? $this->gatewayEndpoint(),
            'user' => $inputs['sshUser'],
            'orbit_path' => '/home/'.self::DEFAULT_RUNTIME_USER.'/orbit',
            'status' => 'active',
        ]);

        try {
            $artifact = app(NodeIdentityArtifactProbe::class)->read($candidate);
        } catch (Throwable) {
            return null;
        }

        if (! $this->identityArtifactMatchesAppRequest($artifact, $name, $initialHostedRoles)) {
            return $this->failCommand(
                code: 'node.incompatible',
                message: "Host '{$inputs['host']}' has an incompatible Orbit node identity.",
                meta: [
                    'name' => $name,
                    'requested_role' => $this->firstRole($initialHostedRoles),
                    'observed_name' => $artifact->name,
                    'observed_role' => $artifact->role,
                    'observed_local_role' => $artifact->localRole,
                    'observed_status' => $artifact->status,
                    'observed_platform' => $artifact->platform,
                ],
            );
        }

        $publicKey = $artifact->interfacePublicKey;
        $wireguardAddress = $artifact->wireguardAddress;

        if (is_string($wireguardAddress) && $wireguardAddress !== '') {
            $developmentDnsMappingFailure = $this->guardDevelopmentDnsMappingAvailable($inputs['tld'], $wireguardAddress);

            if (is_int($developmentDnsMappingFailure)) {
                return $developmentDnsMappingFailure;
            }
        }

        try {
            $peerReality = is_string($publicKey) && $publicKey !== ''
                ? app(WireGuardPeerRealityProbe::class)->peers()[$publicKey] ?? null
                : null;
        } catch (Throwable) {
            $peerReality = null;
        }

        if (
            ! is_string($publicKey)
            || $publicKey === ''
            || ! is_string($wireguardAddress)
            || $wireguardAddress === ''
            || $peerReality === null
            || count($peerReality->allowedAddresses) !== 1
            || $peerReality->allowedAddresses[0] !== $wireguardAddress
        ) {
            return $this->failCommand(
                code: 'node.provisioning_incomplete',
                message: "App host '{$inputs['host']}' could not prove compatible WireGuard identity.",
                meta: [
                    'host' => $inputs['host'],
                    'step' => 'node_identity_adoption',
                    'error' => 'Identity artifact and live WireGuard peer reality did not match.',
                ],
            );
        }

        $node = $registryWriter->writeAppNode(
            name: $name,
            tld: $inputs['tld'],
            host: $inputs['host'],
            wireguardAddress: $wireguardAddress,
            gatewayEndpoint: $inputs['gatewayEndpoint'] ?? $this->gatewayEndpoint(),
            sshUser: $inputs['sshUser'],
            user: self::DEFAULT_RUNTIME_USER,
            status: NodeStatus::Active,
        );

        $node->update([
            'platform' => $artifact->platform,
        ]);

        return $this->adoptExistingAppNode($nodesProbe, $nodeConverger, $node->refresh(), $inputs, $roleAssignmentService, $initialHostedRoles, $appProductionIngressNodeId);
    }

    /**
     * @param  list<string>  $initialHostedRoles
     */
    private function identityArtifactMatchesAppRequest(NodeIdentityArtifact $artifact, string $name, array $initialHostedRoles): bool
    {
        $requestedAppRoles = array_values(array_intersect($initialHostedRoles, [
            NodeRoleName::AppDevelopment->value,
            NodeRoleName::AppProduction->value,
        ]));

        return $artifact->name === $name
            && in_array($artifact->role, $requestedAppRoles, true)
            && in_array($artifact->localRole, $requestedAppRoles, true)
            && $artifact->status === 'active'
            && is_string($artifact->platform)
            && str_starts_with($artifact->platform, 'ubuntu_');
    }

    /**
     * @param  array{host: string, tld: ?string, sshUser: string, gatewayEndpoint: ?string, hostKeyFingerprint: ?string}  $inputs
     * @param  list<string>  $initialHostedRoles
     */
    private function adoptExistingAppNode(
        NodesProbe $nodesProbe,
        NodeConverger $nodeConverger,
        Node $node,
        array $inputs,
        NodeRoleAssignmentService $roleAssignmentService,
        array $initialHostedRoles = [],
        ?int $appProductionIngressNodeId = null,
    ): int {
        $incompatibleFields = [];

        if (! $this->nodeCanAdoptAppHostingRole($node, $initialHostedRoles)) {
            $incompatibleFields['role'] = $node->displayRole();
        }

        if ($node->host !== $inputs['host']) {
            $incompatibleFields['host'] = $node->host;
        }

        if ($node->tld !== $inputs['tld']) {
            $incompatibleFields['tld'] = $node->tld;
        }

        if ($incompatibleFields !== []) {
            return $this->failCommand(
                code: 'node.incompatible',
                message: "Node '{$node->name}' is not compatible with this adoption request.",
                meta: [
                    'name' => $node->name,
                    'requested_role' => $this->firstRole($initialHostedRoles),
                    'incompatible_fields' => $incompatibleFields,
                ],
            );
        }

        if ($node->isActive()) {
            $roleAssignmentFailure = $this->ensureInitialHostedRoles($node, $roleAssignmentService, $initialHostedRoles, $inputs['tld'], $appProductionIngressNodeId);

            if (is_int($roleAssignmentFailure)) {
                return $roleAssignmentFailure;
            }

            $node->refresh();
        }

        $results = $nodesProbe->adopt($node, $nodesProbe->snapshotForAdopt($node));
        $hasConflict = false;
        $activated = false;

        foreach ($results as $result) {
            if ($result->action === AdoptAction::Conflict) {
                $hasConflict = true;
            }

            if (
                in_array($result->key, ['node.wireguard_peer_missing', 'node.wireguard_peer_extra'], true)
                && $result->action === AdoptAction::Updated
            ) {
                $activated = true;
            }
        }

        $node->refresh();

        if ($hasConflict || ! $activated || ! $node->isActive()) {
            return $this->failCommand(
                code: 'node.provisioning_incomplete',
                message: "App node '{$node->name}' could not be safely adopted.",
                meta: [
                    'node' => $node->name,
                    'step' => 'node_adoption',
                    'error' => 'Run `orbit doctor --family=node --adopt --node='.$node->name.'` after resolving the reported node drift.',
                    'adoption_results' => array_map(fn ($result): array => $result->toArray(), $results),
                ],
            );
        }

        $roleAssignmentFailure = $this->ensureInitialHostedRoles($node, $roleAssignmentService, $initialHostedRoles, $inputs['tld'], $appProductionIngressNodeId);

        if (is_int($roleAssignmentFailure)) {
            return $roleAssignmentFailure;
        }

        $developmentDns = app(DevelopmentDnsMappingEnactor::class)->converge($node);

        $nodeSetup = $this->setupManagedNode($nodeConverger, $node, $initialHostedRoles);

        if (is_int($nodeSetup)) {
            return $nodeSetup;
        }

        if ($initialHostedRoles !== [] && ($developmentDns['status'] ?? null) === 'already_configured') {
            $developmentDns['status'] = 'configured';
        }

        $payload = [
            'result' => [
                'action' => 'adopted',
            ],
            'node' => [
                'name' => $node->name,
                'tld' => $node->tld,
                'platform' => $node->platform ?? 'unknown',
                'addresses' => [
                    'wireguard' => $node->wireguard_address,
                    'gateway_endpoint' => $node->gateway_endpoint,
                ],
                'status' => 'active',
            ],
            'provisioning' => [
                'transport' => 'none',
                'host' => $inputs['host'],
                'status' => 'adopted',
            ],
            'next_steps' => [],
        ];

        if ($this->nodeHasActiveRole($node, NodeRoleName::AppDevelopment->value)) {
            $payload['development_tld'] = [
                'tld' => $node->tld,
                'gateway_dns' => [
                    'domain' => "*.{$node->tld}",
                    'target' => $node->wireguard_address,
                    'status' => $developmentDns['status'],
                ],
            ];
        }

        if ($this->wantsJson()) {
            return $this->jsonSuccess($payload);
        }

        $this->info("Adopted app node {$node->name}.");

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $initialHostedRoles
     */
    private function nodeCanAdoptAppHostingRole(Node $node, array $initialHostedRoles): bool
    {
        if (app(NodeRoleAssignments::class)->nodeHasActiveAppHostRole($node)) {
            return $this->nodeHasAnyActiveRole($node, array_values(array_intersect($initialHostedRoles, [
                NodeRoleName::AppDevelopment->value,
                NodeRoleName::AppProduction->value,
            ])));
        }

        if (! $this->containsAppHostingRole($initialHostedRoles)) {
            return false;
        }

        return ! $node->roleAssignments()
            ->where('status', NodeRoleStatus::Active->value)
            ->exists();
    }

    /**
     * @param  list<string>  $roles
     */
    private function containsDevelopmentAppRole(array $roles): bool
    {
        return in_array(NodeRoleName::AppDevelopment->value, $roles, true);
    }

    /**
     * @param  list<string>  $roles
     */
    private function nodeHasAnyActiveRole(Node $node, array $roles): bool
    {
        if ($roles === []) {
            return false;
        }

        return $node->roleAssignments()
            ->whereIn('role', $roles)
            ->where('status', NodeRoleStatus::Active->value)
            ->exists();
    }

    private function nodeHasActiveRole(Node $node, string $role): bool
    {
        return $node->roleAssignments()
            ->where('role', $role)
            ->where('status', NodeRoleStatus::Active->value)
            ->exists();
    }

    private function authorizeRuntimeSshUser(Node $node, string $sshUser, string $runtimeUser): ?int
    {
        $publicKey = $this->gatewaySshPublicKey();

        if (is_int($publicKey)) {
            return $publicKey;
        }

        $home = $runtimeUser === 'root' ? '/root' : "/home/{$runtimeUser}";
        $authorizedKeys = "{$home}/.ssh/authorized_keys";
        $script = sprintf(
            'sudo install -d -m 700 -o %1$s -g %1$s %2$s && sudo touch %3$s && sudo chown %1$s:%1$s %3$s && sudo chmod 600 %3$s && (sudo grep -qxF %4$s %3$s || printf "%%s\n" %4$s | sudo tee -a %3$s >/dev/null)',
            escapeshellarg($runtimeUser),
            escapeshellarg("{$home}/.ssh"),
            escapeshellarg($authorizedKeys),
            escapeshellarg($publicKey),
        );

        $authorization = Process::timeout(30)->run($this->ssh(
            user: $sshUser,
            host: $node->host,
            command: $script,
            node: $node,
        ));

        if ($authorization->successful()) {
            return null;
        }

        return $this->failCommand(
            code: 'node.provisioning_incomplete',
            message: "Host '{$node->host}' could not authorize the steady-state SSH user.",
            meta: [
                'host' => $node->host,
                'step' => 'steady_state_ssh_authorization',
                'error' => trim($authorization->errorOutput()) ?: trim($authorization->output()) ?: null,
            ],
        );
    }

    private function hardenRuntimeSshAccess(Node $node, string $runtimeUser): ?int
    {
        $script = sprintf(
            <<<'SCRIPT'
set -e
RUNTIME_USER=%s
sudo install -d -m 0755 /etc/ssh/sshd_config.d
sudo tee /etc/ssh/sshd_config.d/99-orbit-hardening.conf > /dev/null <<EOF
# Managed by Orbit.
# Provisioned nodes accept operator SSH only through the orbit system user.
PermitRootLogin no
PasswordAuthentication no
KbdInteractiveAuthentication no
ChallengeResponseAuthentication no
PubkeyAuthentication yes
AllowUsers ${RUNTIME_USER}
EOF
sudo chmod 0644 /etc/ssh/sshd_config.d/99-orbit-hardening.conf
sudo sshd -t
sudo systemctl reload ssh 2>/dev/null || sudo systemctl reload sshd
sudo passwd -l root > /dev/null 2>&1 || true
sudo rm -f /root/.ssh/authorized_keys
SCRIPT,
            escapeshellarg($runtimeUser),
        );

        $hardening = Process::timeout(60)->run($this->ssh(
            user: $runtimeUser,
            host: $node->host,
            command: $script,
            node: $node,
        ));

        if ($hardening->successful()) {
            return null;
        }

        return $this->failCommand(
            code: 'node.provisioning_incomplete',
            message: "Host '{$node->host}' could not harden steady-state SSH access.",
            meta: [
                'host' => $node->host,
                'step' => 'ssh_hardening',
                'error' => trim($hardening->errorOutput()."\n".$hardening->output()) ?: null,
            ],
        );
    }

    private function finalizeNodeSecurityBaseline(Node $node): ?int
    {
        $shell = app(RemoteShell::class);

        /** @var array<string, SecurityInstaller> $installers */
        $installers = [
            'sshd' => app(SshdHardenedInstaller::class),
            'public_ssh_deny' => app(PublicSshDenyInstaller::class),
        ];

        foreach ($installers as $step => $installer) {
            $report = $installer->installFor($node, $shell);

            if ($report->successful) {
                continue;
            }

            return $this->failCommand(
                code: 'node.provisioning_incomplete',
                message: "Host '{$node->host}' could not finalize the node security baseline.",
                meta: [
                    'host' => $node->host,
                    'step' => $step,
                    'exit_code' => $report->details['exit_code'] ?? null,
                ],
            );
        }

        return null;
    }

    private function gatewaySshPublicKey(): string|int
    {
        $publicKey = Process::timeout(30)->run('ssh-keygen -y -f ~/.ssh/id_ed25519');

        if ($publicKey->successful() && trim($publicKey->output()) !== '') {
            return trim($publicKey->output());
        }

        return $this->failCommand(
            code: 'node.provisioning_incomplete',
            message: 'Gateway SSH identity is not available for steady-state access.',
            meta: [
                'step' => 'steady_state_ssh_authorization',
                'error' => trim($publicKey->errorOutput()) ?: trim($publicKey->output()) ?: null,
            ],
        );
    }

    private function controlWireGuardConfig(
        string $controlPrivateKey,
        string $controlWireguardAddress,
        string $gatewayPublicKey,
        string $gatewayWireguardAddress,
        string $gatewayEndpoint,
        ?string $preSharedKey = null,
        ?string $allowedIps = null,
    ): string {
        $lines = [
            '[Interface]',
            "PrivateKey = {$controlPrivateKey}",
            "Address = {$controlWireguardAddress}/24",
            '',
            '[Peer]',
            "PublicKey = {$gatewayPublicKey}",
        ];

        if ($preSharedKey !== null) {
            $lines[] = "PresharedKey = {$preSharedKey}";
        }

        return implode("\n", [
            ...$lines,
            'AllowedIPs = '.($allowedIps ?? "{$gatewayWireguardAddress}/32"),
            "Endpoint = {$gatewayEndpoint}:51820",
            'PersistentKeepalive = 25',
            '',
        ]);
    }

    private function generatePreSharedKey(): string
    {
        try {
            return base64_encode(random_bytes(32));
        } catch (Throwable $exception) {
            throw new RuntimeException('WireGuard pre-shared key generation failed.', previous: $exception);
        }
    }

    private function installerFailure(string $role, string $host, string $sshUser, string $errorOutput): int
    {
        $error = trim($errorOutput);

        if ($this->isSshAuthorizationFailure($error)) {
            return $this->failCommand(
                code: 'authorization_failed',
                message: "Gateway cannot SSH to {$sshUser}@{$host}.",
                meta: [
                    'host' => $host,
                    'user' => $sshUser,
                    'step' => 'ssh_authorization',
                    'error' => $error !== '' ? $error : null,
                ],
            );
        }

        return $this->failCommand(
            code: 'node.provisioning_incomplete',
            message: ucfirst($role)." host '{$host}' could not complete Orbit installation.",
            meta: [
                'host' => $host,
                'step' => 'install_orbit',
                'error' => $error !== '' ? $error : null,
            ],
        );
    }

    private function isSshAuthorizationFailure(string $error): bool
    {
        return str_contains($error, 'Permission denied')
            || str_contains($error, 'publickey')
            || str_contains($error, 'Authentication failed');
    }

    private function gatewayConfigured(): bool
    {
        if ($this->gatewayQuery()->exists()) {
            return true;
        }

        return $this->gatewayApiConfigured();
    }

    private function gatewayApiConfigured(): bool
    {
        return LocalGatewaySettings::query()
            ->whereNotNull('gateway_url')
            ->where('gateway_url', '!=', '')
            ->whereNotNull('ca_pem_path')
            ->where('ca_pem_path', '!=', '')
            ->exists();
    }

    private function stringArgument(string $name): ?string
    {
        $value = $this->argument($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return list<string>
     */
    private function arrayOption(string $name): array
    {
        $value = $this->option($name);

        if (is_string($value) && $value !== '') {
            return [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, fn ($item): bool => is_string($item) && $item !== ''));
    }

    private function resolveName(): ?string
    {
        $name = $this->stringArgument('name');

        if ($name !== null) {
            return $name;
        }

        if (! $this->isInteractiveInput()) {
            return null;
        }

        return trim(text(
            label: 'Node name',
            required: true,
            validate: fn (string $value): ?string => $this->validatePromptNodeName($value),
        ));
    }

    private function resolveHost(string $role): ?string
    {
        $host = $this->stringOption('host');

        if ($host !== null) {
            return $host;
        }

        if (! $this->isInteractiveInput()) {
            return null;
        }

        if (! in_array($role, ['app-dev', 'app-prod', 'agent', 'ingress', 'gateway'], true)) {
            return null;
        }

        return trim(text(
            label: 'Host',
            required: true,
            validate: fn (string $value): ?string => $this->validatePromptHost($value),
        ));
    }

    private function resolveSshUser(): string
    {
        $sshUser = $this->stringOption('user') ?? 'root';

        if (! $this->isInteractiveInput() || $this->sshUserOptionWasSupplied()) {
            return $sshUser;
        }

        return trim(text(label: 'SSH user', default: $sshUser, required: true));
    }

    private function sshUserOptionWasSupplied(): bool
    {
        return array_key_exists('--user', $this->arguments);
    }

    private function forbiddenClientIdentityInput(): ?string
    {
        foreach (['host', 'operator-name', 'tld', 'ingress', 'redis-node', 's3-data-path', 'gateway-endpoint', 'host-key-fingerprint'] as $option) {
            if ($this->stringOption($option) !== null) {
                return $option;
            }
        }

        foreach (['agent-tool', 'grant-to', 'grant-from'] as $option) {
            if ($this->arrayOption($option) !== []) {
                return $option;
            }
        }

        foreach (['self-grant', 'self-grant-permissions', 'grant-to-preset', 'grant-to-permissions', 'grant-from-preset', 'grant-from-permissions'] as $option) {
            if ($this->stringOption($option) !== null) {
                return $option;
            }
        }

        if ($this->sshUserOptionWasSupplied()) {
            return 'user';
        }

        return null;
    }

    private function isInteractiveInput(): bool
    {
        return false;
    }

    private function validatePromptNodeName(string $value): ?string
    {
        $name = trim($value);

        if ($name === '') {
            return 'Node name is required.';
        }

        return $this->isValidNodeName($name) ? null : 'Node name must be a valid node name.';
    }

    private function validatePromptHost(string $value): ?string
    {
        $host = trim($value);

        if ($host === '') {
            return 'Host is required.';
        }

        return $this->isValidHost($host) ? null : 'Host must be a valid IP address or dotted DNS name.';
    }

    /**
     * @param  list<string>  $roles
     * @return array{host: string, tld: ?string, sshUser: ?string, gatewayEndpoint: ?string, hostKeyFingerprint: ?string}|int
     */
    private function resolveHostedRoleInputs(array $roles): array|int
    {
        $needsHost = array_intersect($roles, [
            NodeRoleName::AppDevelopment->value,
            NodeRoleName::AppProduction->value,
            NodeRoleName::Database->value,
            NodeRoleName::Ingress->value,
            NodeRoleName::Agent->value,
        ]) !== [];

        if (! $needsHost && $this->stringOption('host') !== null) {
            return $this->validationFailed('host', 'Only app-dev, app-prod, database, ingress, agent, and gateway use host provisioning.');
        }

        if (! $needsHost && $this->stringOption('host-key-fingerprint') !== null) {
            return $this->validationFailed('host_key_fingerprint', 'Only app-dev, app-prod, database, ingress, agent, and gateway use host-key fingerprint pinning.');
        }

        if (! $needsHost && $this->stringOption('gateway-endpoint') !== null) {
            return $this->validationFailed('gateway_endpoint', 'Only app-dev, app-prod, database, ingress, agent, and gateway use WireGuard endpoint overrides.');
        }

        $hostRole = array_first(array_intersect($roles, [
            NodeRoleName::AppDevelopment->value,
            NodeRoleName::AppProduction->value,
            NodeRoleName::Database->value,
            NodeRoleName::Ingress->value,
            NodeRoleName::Agent->value,
        ])) ?? NodeRoleName::Agent->value;

        $host = $needsHost ? $this->resolveHost($hostRole) : null;

        if ($needsHost && $host === null) {
            return $this->validationFailed('host', 'Host is required for hosted roles that provision a host.');
        }

        if ($host !== null && ! $this->isValidHost($host)) {
            return $this->validationFailed('host', 'Host must be a valid IP address or dotted DNS name.');
        }

        $gatewayEndpoint = $this->stringOption('gateway-endpoint');

        if ($gatewayEndpoint !== null && ! $this->isValidHost($gatewayEndpoint)) {
            return $this->validationFailed('gateway_endpoint', 'Gateway endpoint must be a valid IP address or dotted DNS name.');
        }

        $tld = $this->stringOption('tld');

        if (in_array(NodeRoleName::Agent->value, $roles, true) && $tld === null) {
            $tld = 'agent';
        }

        if (in_array(NodeRoleName::AppDevelopment->value, $roles, true) && $tld === null && $this->isInteractiveInput()) {
            $tld = trim(text(
                label: 'Development TLD',
                required: true,
                validate: fn (string $value): ?string => $this->isValidTld(trim($value))
                    ? null
                    : 'TLD must be a lowercase DNS label without a leading dot.',
            ));
        }

        if (array_intersect($roles, [NodeRoleName::AppDevelopment->value, NodeRoleName::Database->value, NodeRoleName::Agent->value]) !== []) {
            if ($tld === null) {
                return $this->validationFailed('tld', 'App development, database, and agent nodes require a TLD.');
            }

            if (! $this->isValidTld($tld)) {
                return $this->validationFailed('tld', 'TLD must be a lowercase DNS label without a leading dot.');
            }
        } elseif ($tld !== null) {
            return $this->validationFailed('tld', 'Only app-dev, database, and agent use a TLD.');
        }

        return [
            'host' => $host ?? '',
            'tld' => $tld,
            'sshUser' => $needsHost ? $this->resolveSshUser() : null,
            'gatewayEndpoint' => $needsHost ? $gatewayEndpoint : null,
            'hostKeyFingerprint' => $needsHost ? $this->stringOption('host-key-fingerprint') : null,
        ];
    }

    /**
     * @param  list<string>  $roles
     */
    private function containsAppHostingRole(array $roles): bool
    {
        return array_intersect($roles, [
            NodeRoleName::AppDevelopment->value,
            NodeRoleName::AppProduction->value,
        ]) !== [];
    }

    /**
     * @param  list<string>  $roles
     */
    private function firstRole(array $roles): string
    {
        if ($roles === []) {
            throw new RuntimeException('Expected at least one hosted role.');
        }

        return $roles[0];
    }

    /**
     * @return array<string, mixed>
     */
    private function settingsForRole(string $role, ?string $tld): array
    {
        if (in_array($role, [NodeRoleName::AppDevelopment->value, NodeRoleName::Agent->value], true)) {
            return ['tld' => $tld];
        }

        return [];
    }

    /**
     * @param  list<string>  $roles
     */
    private function ensureInitialHostedRoles(
        Node $node,
        NodeRoleAssignmentService $roleAssignmentService,
        array $roles,
        ?string $tld,
        ?int $appProductionIngressNodeId = null,
    ): ?int {
        foreach ($this->orderHostedRoles($roles) as $role) {
            $existingAssignment = $node->roleAssignments()->where('role', $role)->first();
            $settings = $role === NodeRoleName::AppProduction->value
                ? ['ingress_node_id' => $appProductionIngressNodeId ?? $node->id]
                : $this->settingsForRole($role, $tld);

            $assignment = $existingAssignment instanceof NodeRoleAssignment
                ? $roleAssignmentService->update($node, $role, $settings)
                : $roleAssignmentService->addDuringCreation($node, $role, $settings);

            if ($assignment->status !== NodeRoleStatus::Error) {
                continue;
            }

            return $this->failCommand(
                code: 'node.provisioning_incomplete',
                message: "Node '{$node->name}' created but hosted role '{$assignment->role}' failed to converge.",
                meta: [
                    'node' => $node->name,
                    'role' => $assignment->role,
                    'status' => $assignment->status->value,
                    'settings' => $assignment->settings ?? [],
                    'last_error' => $assignment->last_error,
                ],
            );
        }

        return null;
    }

    /**
     * @param  list<string>  $roles
     */
    private function setupManagedNode(NodeConverger $nodeConverger, Node $node, array $roles): ?int
    {
        if (! $this->containsDevelopmentAppRole($roles)) {
            return null;
        }

        $freshNode = $node->fresh();

        $result = $nodeConverger->converge(
            node: $freshNode instanceof Node ? $freshNode : $node,
            context: NodeConvergenceContext::Setup,
            families: ['node', 'tool'],
        );

        if ($result->successful()) {
            return null;
        }

        return $this->failCommand(
            code: 'node.provisioning_incomplete',
            message: "Node '{$node->name}' created but managed setup did not complete.",
            meta: [
                'node' => $node->name,
                'step' => 'node_setup',
                'setup' => $result->toArray(),
            ],
        );
    }

    /**
     * @param  list<string>  $roles
     * @return array{roles: list<string>, ingress_node_id: ?int, ingress_node_name: ?string}|int
     */
    private function resolveIngressPlacement(array $roles, bool $validateLocalIngressRegistry = true): array|int
    {
        $roles = array_values(array_unique($roles));
        $ingressNodeName = $this->stringOption('ingress');

        if ($ingressNodeName !== null && (! in_array(NodeRoleName::AppProduction->value, $roles, true) || in_array(NodeRoleName::Ingress->value, $roles, true))) {
            return $this->failCommand(
                code: 'validation_failed',
                message: '--ingress is only supported for private app-prod placement.',
                meta: ['field' => 'ingress_node'],
            );
        }

        if (! in_array(NodeRoleName::AppProduction->value, $roles, true)) {
            return [
                'roles' => $roles,
                'ingress_node_id' => null,
                'ingress_node_name' => null,
            ];
        }

        if (in_array(NodeRoleName::Ingress->value, $roles, true)) {
            return [
                'roles' => $this->orderHostedRoles($roles),
                'ingress_node_id' => null,
                'ingress_node_name' => null,
            ];
        }

        if ($ingressNodeName !== null) {
            if (! $validateLocalIngressRegistry) {
                return [
                    'roles' => $this->orderHostedRoles($roles),
                    'ingress_node_id' => null,
                    'ingress_node_name' => $ingressNodeName,
                ];
            }

            $ingressNode = $this->findActiveIngressNodeByName($ingressNodeName);

            if (! $ingressNode instanceof Node) {
                return $this->missingIngressPlacement();
            }

            return [
                'roles' => $this->orderHostedRoles($roles),
                'ingress_node_id' => $ingressNode->id,
                'ingress_node_name' => $ingressNode->name,
            ];
        }

        if (! $this->isInteractiveInput()) {
            return $this->missingIngressPlacement('App-production requires explicit ingress placement.');
        }

        if (confirm(label: 'Serve public traffic from this node?', default: true)) {
            return [
                'roles' => $this->orderHostedRoles([...$roles, NodeRoleName::Ingress->value]),
                'ingress_node_id' => null,
                'ingress_node_name' => null,
            ];
        }

        $ingressNodes = $this->activeIngressNodes();

        if ($ingressNodes === []) {
            return $this->missingIngressPlacement();
        }

        $selectedNodeName = select(
            label: 'Ingress node',
            options: $ingressNodes,
            required: true,
        );

        $ingressNode = $this->findActiveIngressNodeByName($selectedNodeName);

        if (! $ingressNode instanceof Node) {
            return $this->missingIngressPlacement();
        }

        return [
            'roles' => $this->orderHostedRoles($roles),
            'ingress_node_id' => $ingressNode->id,
            'ingress_node_name' => $ingressNode->name,
        ];
    }

    /**
     * @param  list<string>  $roles
     * @return list<string>
     */
    private function orderHostedRoles(array $roles): array
    {
        return collect($roles)
            ->sortBy(fn (string $role): int => match ($role) {
                NodeRoleName::Ingress->value => 10,
                NodeRoleName::AppProduction->value => 20,
                default => 30,
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function activeIngressNodes(): array
    {
        return Node::query()
            ->where('status', NodeStatus::Active->value)
            ->whereHas('roleAssignments', fn (Builder $query) => $query
                ->where('role', NodeRoleName::Ingress->value)
                ->where('status', NodeRoleStatus::Active->value))
            ->orderBy('name')
            ->pluck('name')
            ->all();
    }

    private function findActiveIngressNodeByName(string $name): ?Node
    {
        return Node::query()
            ->where('name', $name)
            ->where('status', NodeStatus::Active->value)
            ->whereHas('roleAssignments', fn (Builder $query) => $query
                ->where('role', NodeRoleName::Ingress->value)
                ->where('status', NodeRoleStatus::Active->value))
            ->first();
    }

    private function missingIngressPlacement(string $message = 'Private app-prod nodes require an active ingress node. Create one first with: orbit node:new edge-1 --template=ingress'): int
    {
        return $this->failCommand(
            code: 'validation_failed',
            message: $message,
            meta: [
                'field' => 'ingress_node',
                'required_role' => NodeRoleName::Ingress->value,
            ],
        );
    }

    private function setupAgentSelfGrant(Node $node): ?int
    {
        $selfGrantMode = $this->stringOption('self-grant') ?? 'default';
        $selfGrantPermissions = $this->stringOption('self-grant-permissions');

        if (! in_array($selfGrantMode, ['default', 'custom'], true)) {
            return $this->validationFailed('self-grant', 'Self-grant mode must be one of default or custom.');
        }

        $materializer = app(RoleSelfGrantMaterializer::class);

        if ($selfGrantMode === 'default') {
            $materializer->materializeOnRoleApplied($node, NodeRoleName::Agent);

            return null;
        }

        $permissions = $this->resolveGrantPermissions(null, $selfGrantPermissions);

        if (is_int($permissions)) {
            return $permissions;
        }

        $materializer->replaceCustomSelfPermissions($node, $permissions);

        return null;
    }

    private function setupGrantTo(Node $node): ?int
    {
        $targets = $this->arrayOption('grant-to');

        if ($targets === []) {
            return null;
        }

        $preset = $this->stringOption('grant-to-preset');
        $permissionsInput = $this->stringOption('grant-to-permissions');

        $permissions = $this->resolveGrantPermissions($preset, $permissionsInput);
        if (is_int($permissions)) {
            return $permissions;
        }

        $resolvedTargets = $this->resolveGrantTargets($targets, $node->id);
        if (is_int($resolvedTargets)) {
            return $resolvedTargets;
        }

        foreach ($resolvedTargets as $targetNode) {
            NodeAccess::query()->firstOrCreate([
                'consumer_node_id' => $node->id,
                'serving_node_id' => $targetNode->id,
            ], [
                'permissions' => $permissions,
            ]);
        }

        return null;
    }

    private function setupGrantFrom(Node $node): ?int
    {
        $sources = $this->arrayOption('grant-from');

        if ($sources === []) {
            return null;
        }

        $preset = $this->stringOption('grant-from-preset');
        $permissionsInput = $this->stringOption('grant-from-permissions');

        $permissions = $this->resolveGrantPermissions($preset, $permissionsInput);
        if (is_int($permissions)) {
            return $permissions;
        }

        $resolvedSources = $this->resolveGrantTargets($sources, $node->id);
        if (is_int($resolvedSources)) {
            return $resolvedSources;
        }

        foreach ($resolvedSources as $sourceNode) {
            NodeAccess::query()->firstOrCreate([
                'consumer_node_id' => $sourceNode->id,
                'serving_node_id' => $node->id,
            ], [
                'permissions' => $permissions,
            ]);
        }

        return null;
    }

    /**
     * @param  array<int, string>  $options
     * @return list<Node>|int
     */
    private function resolveGrantTargets(array $options, ?int $excludeNodeId = null): array|int
    {
        $targets = [];
        $hasAll = false;

        foreach ($options as $option) {
            if (! is_string($option) || $option === '') {
                continue;
            }

            if ($option === 'all') {
                $hasAll = true;

                continue;
            }

            $targetNode = Node::query()
                ->where('name', $option)
                ->where('status', NodeStatus::Active->value)
                ->first();

            if (! $targetNode instanceof Node) {
                return $this->failCommand(
                    code: 'node.not_found',
                    message: "Grant target node '{$option}' not found.",
                    meta: ['node' => $option],
                );
            }

            $targets[] = $targetNode;
        }

        if ($hasAll) {
            $allNodes = Node::query()
                ->where('status', NodeStatus::Active->value)
                ->get();

            foreach ($allNodes as $allNode) {
                if ($excludeNodeId !== null && $allNode->id === $excludeNodeId) {
                    continue;
                }
                $alreadyIncluded = array_any($targets, fn ($target) => $target->id === $allNode->id);

                if (! $alreadyIncluded) {
                    $targets[] = $allNode;
                }
            }
        }

        return $targets;
    }

    /**
     * @return list<string>|int
     */
    private function resolveGrantPermissions(?string $preset, ?string $permissionsInput): array|int
    {
        if ($preset !== null && $permissionsInput !== null) {
            return $this->failCommand(
                code: 'validation_failed',
                message: 'Use either --preset or --permissions, not both.',
                meta: ['fields' => ['preset', 'permissions']],
            );
        }

        if ($preset !== null) {
            if ($preset === 'gateway-admin') {
                return $this->failCommand(
                    code: 'validation_failed',
                    message: 'Gateway-admin is not offered by default. Use node:grant with --force to create a gateway-admin grant.',
                    meta: ['field' => 'preset', 'preset' => 'gateway-admin'],
                );
            }

            try {
                return app(NodePermissionPresets::class)->permissions($preset);
            } catch (InvalidArgumentException $e) {
                return $this->failCommand(
                    code: 'validation_failed',
                    message: $e->getMessage(),
                    meta: ['field' => 'preset', 'preset' => $preset],
                );
            }
        }

        if ($permissionsInput !== null) {
            $permissions = array_map(trim(...), explode(',', $permissionsInput));
            $permissions = array_values(array_filter($permissions));

            if ($permissions === []) {
                return $this->failCommand(
                    code: 'validation_failed',
                    message: 'Permission set cannot be empty.',
                    meta: ['field' => 'permissions'],
                );
            }

            try {
                $normalized = app(NodePermissionNormalizer::class)->normalize($permissions);
            } catch (InvalidArgumentException $e) {
                return $this->failCommand(
                    code: 'validation_failed',
                    message: $e->getMessage(),
                    meta: ['field' => 'permissions'],
                );
            }

            return $normalized->permissions;
        }

        return $this->failCommand(
            code: 'validation_failed',
            message: 'Use --preset or --permissions to specify grant permissions.',
            meta: ['fields' => ['preset', 'permissions']],
        );
    }

    /**
     * @param  list<string>  $roles
     */
    private function preflightAgentSetup(array $roles): ?int
    {
        $hasAgentRole = in_array(NodeRoleName::Agent->value, $roles, true);

        $tools = $this->arrayOption('agent-tool');
        if ($tools !== [] && ! $hasAgentRole) {
            return $this->failCommand(
                code: 'validation_failed',
                message: 'Agent tools can only be specified for agent nodes.',
                meta: ['field' => 'agent-tool'],
            );
        }

        $selfGrantMode = $this->stringOption('self-grant');
        if ($selfGrantMode !== null && ! in_array($selfGrantMode, ['default', 'custom'], true)) {
            return $this->validationFailed('self-grant', 'Self-grant mode must be one of default or custom.');
        }

        $selfGrantPermissions = $this->stringOption('self-grant-permissions');
        if ($selfGrantPermissions !== null && $selfGrantMode !== 'custom') {
            return $this->failCommand(
                code: 'validation_failed',
                message: 'Use --self-grant=custom when supplying --self-grant-permissions.',
                meta: ['fields' => ['self-grant', 'self-grant-permissions']],
            );
        }

        $grantToTargets = $this->arrayOption('grant-to');
        if ($grantToTargets !== []) {
            $resolved = $this->resolveGrantTargets($grantToTargets);
            if (is_int($resolved)) {
                return $resolved;
            }
        }

        $grantFromSources = $this->arrayOption('grant-from');
        if ($grantFromSources !== []) {
            $resolved = $this->resolveGrantTargets($grantFromSources);
            if (is_int($resolved)) {
                return $resolved;
            }
        }

        $grantToPreset = $this->stringOption('grant-to-preset');
        $grantToPermissions = $this->stringOption('grant-to-permissions');
        if ($grantToTargets === [] && ($grantToPreset !== null || $grantToPermissions !== null)) {
            return $this->failCommand(
                code: 'validation_failed',
                message: 'Use --grant-to to specify target nodes when supplying --grant-to-preset or --grant-to-permissions.',
                meta: ['fields' => ['grant-to', 'grant-to-preset', 'grant-to-permissions']],
            );
        }
        if ($grantToTargets !== []) {
            $permissions = $this->resolveGrantPermissions($grantToPreset, $grantToPermissions);
            if (is_int($permissions)) {
                return $permissions;
            }
        }

        $grantFromPreset = $this->stringOption('grant-from-preset');
        $grantFromPermissions = $this->stringOption('grant-from-permissions');
        if ($grantFromSources === [] && ($grantFromPreset !== null || $grantFromPermissions !== null)) {
            return $this->failCommand(
                code: 'validation_failed',
                message: 'Use --grant-from to specify source nodes when supplying --grant-from-preset or --grant-from-permissions.',
                meta: ['fields' => ['grant-from', 'grant-from-preset', 'grant-from-permissions']],
            );
        }
        if ($grantFromSources !== []) {
            $permissions = $this->resolveGrantPermissions($grantFromPreset, $grantFromPermissions);
            if (is_int($permissions)) {
                return $permissions;
            }
        }

        if ($selfGrantMode === 'custom' || $selfGrantPermissions !== null) {
            $permissions = $this->resolveGrantPermissions(null, $selfGrantPermissions);
            if (is_int($permissions)) {
                return $permissions;
            }
        }

        if ($hasAgentRole && $tools !== []) {
            $catalog = app(ToolCatalog::class);
            $resolvedTools = [];
            foreach ($tools as $tool) {
                if (! $catalog->supports($tool)) {
                    return $this->failCommand(
                        code: 'validation_failed',
                        message: "Unknown agent tool '{$tool}'.",
                        meta: ['field' => 'agent-tool', 'tool' => $tool],
                    );
                }

                if ($catalog->category($tool) !== 'agent') {
                    return $this->failCommand(
                        code: 'validation_failed',
                        message: "Tool '{$tool}' is not an agent tool.",
                        meta: ['field' => 'agent-tool', 'tool' => $tool],
                    );
                }

                $resolvedTools[] = $tool;
            }

            if (count($resolvedTools) > 1 && ! $this->option('json') && $this->isInteractiveInput()) {
                $this->warn('Multiple agent tools selected: '.implode(', ', $resolvedTools));
                if (! confirm('Continue installing all tools?', default: false)) {
                    return $this->failCommand(
                        code: 'user_cancelled',
                        message: 'Agent tool installation cancelled by user.',
                        meta: [],
                    );
                }
            }
        }

        return null;
    }

    /**
     * @param  list<array{code: string, tools: list<string>}>  $warnings
     */
    private function setupAgentTools(Node $node, array &$warnings): ?int
    {
        $tools = $this->arrayOption('agent-tool');

        if ($tools === []) {
            return null;
        }

        $resolvedTools = [];

        foreach ($tools as $tool) {
            $catalog = app(ToolCatalog::class);

            if (! $catalog->supports($tool)) {
                return $this->failCommand(
                    code: 'validation_failed',
                    message: "Unknown agent tool '{$tool}'.",
                    meta: ['field' => 'agent-tool', 'tool' => $tool],
                );
            }

            if ($catalog->category($tool) !== 'agent') {
                return $this->failCommand(
                    code: 'validation_failed',
                    message: "Tool '{$tool}' is not an agent tool.",
                    meta: ['field' => 'agent-tool', 'tool' => $tool],
                );
            }

            $resolvedTools[] = $tool;
        }

        if (count($resolvedTools) > 1) {
            $warnings[] = [
                'code' => 'tool.multiple_agent_tools_running',
                'tools' => $resolvedTools,
            ];
        }

        foreach ($resolvedTools as $tool) {
            $result = app(ToolInstaller::class)->install($tool, $node->name, null, 'installed');

            if ($result instanceof ToolRegistryFailure) {
                return $this->failCommand(
                    code: $result->code,
                    message: $result->message,
                    meta: $result->meta,
                );
            }
        }

        return null;
    }

    private function isValidNodeName(string $name): bool
    {
        return (bool) preg_match('/^[a-z](?:[a-z0-9-]*[a-z0-9])?$/', $name);
    }

    private function isValidTld(string $tld): bool
    {
        return (bool) preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $tld);
    }

    private function isValidHost(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        if (! str_contains($host, '.')) {
            return false;
        }

        if (strlen($host) > 253 || str_contains($host, '..')) {
            return false;
        }

        $labels = explode('.', trim($host, '.'));

        foreach ($labels as $label) {
            if ($label === '' || strlen($label) > 63) {
                return false;
            }

            if (! preg_match('/^[a-zA-Z0-9](?:[a-zA-Z0-9-]*[a-zA-Z0-9])?$/', $label)) {
                return false;
            }
        }

        return true;
    }

    private function gatewayEndpoint(): ?string
    {
        /** @var Node|null $gateway */
        $gateway = $this->gatewayQuery()
            ->first();

        if (! $gateway instanceof Node) {
            return null;
        }

        return $gateway->wireguard_address ?? $gateway->gateway_endpoint ?? $gateway->host;
    }

    private function gatewayQuery(): Builder
    {
        return app(NodeRoleAssignments::class)->activeGatewayNodeQuery();
    }

    /**
     * @param  array<int, string>  $excluding
     */
    private function resolveProvisionedNodeWireguardAddress(array $excluding = []): string|int
    {
        $reservedAddress = $this->e2eReservedWireguardAddress();

        if ($reservedAddress === null) {
            return $this->nextWireguardAddress($excluding);
        }

        if (! $this->isManagedWireguardAddress($reservedAddress)) {
            return $this->validationFailed(
                'wireguard_address',
                'Prepared topology WireGuard address must be in the managed 10.6.0.3-10.6.0.254 range.',
            );
        }

        if (in_array($reservedAddress, $this->usedWireguardAddresses($excluding), true)) {
            return $this->failCommand(
                code: 'node.incompatible',
                message: "WireGuard address '{$reservedAddress}' is already assigned.",
                meta: [
                    'field' => 'wireguard_address',
                    'value' => $reservedAddress,
                ],
            );
        }

        return $reservedAddress;
    }

    /**
     * @param  array<int, string>  $excluding
     */
    private function nextWireguardAddress(array $excluding = []): string
    {
        $used = $this->usedWireguardAddresses($excluding);

        for ($octet = 3; $octet <= 254; $octet++) {
            $candidate = "10.6.0.{$octet}";

            if (! in_array($candidate, $used, true)) {
                return $candidate;
            }
        }

        throw new RuntimeException('No available WireGuard addresses remain in 10.6.0.0/24.');
    }

    /**
     * @param  array<int, string>  $excluding
     * @return list<string>
     */
    private function usedWireguardAddresses(array $excluding = []): array
    {
        $used = Node::query()
            ->whereNotNull('wireguard_address')
            ->pluck('wireguard_address')
            ->all();

        $peerAddresses = WireGuardPeer::query()
            ->whereNotNull('allowed_ips')
            ->pluck('allowed_ips')
            ->flatMap(fn (string $allowedIps): array => $this->wireguardAddressesFromAllowedIps($allowedIps))
            ->all();

        $wgEasyAddresses = app(WgEasyAddressReservationProbe::class)->addresses();

        return array_values(array_unique(array_merge($used, $peerAddresses, $wgEasyAddresses, $excluding)));
    }

    /**
     * @return list<string>
     */
    private function wireguardAddressesFromAllowedIps(string $allowedIps): array
    {
        return array_values(array_filter(array_map(
            fn (string $allowedIp): string => trim(explode('/', trim($allowedIp), 2)[0]),
            explode(',', $allowedIps),
        ), fn (string $address): bool => $address !== ''));
    }

    private function e2eReservedWireguardAddress(): ?string
    {
        $e2e = getenv('ORBIT_E2E');

        if (! is_string($e2e) || $e2e === '' || $e2e === '0') {
            return null;
        }

        $address = getenv('ORBIT_E2E_NODE_WIREGUARD_ADDRESS');

        if (! is_string($address) || trim($address) === '') {
            return null;
        }

        return trim($address);
    }

    private function isManagedWireguardAddress(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }

        $parts = array_map(intval(...), explode('.', $address));

        return $parts[0] === 10
            && $parts[1] === 6
            && $parts[2] === 0
            && $parts[3] >= 3
            && $parts[3] <= 254;
    }

    private function validationFailed(string $field, string $message): int
    {
        return $this->failCommand(
            code: 'validation_failed',
            message: $message,
            meta: ['field' => $field],
        );
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function rollbackProvisioningNode(Node $node, string $reason, array $properties = []): void
    {
        $name = $node->name;

        DB::transaction(function () use ($node): void {
            $node->firewallRules()->delete();
            $node->roleAssignments()->delete();
            $node->nodeTools()->delete();
            WireGuardPeer::query()->where('node_id', $node->id)->delete();
            NodeAccess::query()
                ->where('consumer_node_id', $node->id)
                ->orWhere('serving_node_id', $node->id)
                ->delete();

            $node->delete();
        });

        if (! Schema::hasTable('activity_log')) {
            return;
        }

        activity('node')
            ->event('node.provisioning.failed')
            ->withProperties([
                'type' => 'write',
                'node' => $name,
                'reason' => $reason,
                ...$properties,
            ])
            ->log('node.provisioning.failed');
    }

    private function guardDevelopmentDnsMappingAvailable(?string $tld, string $target): ?int
    {
        if ($tld === null) {
            return null;
        }

        $path = app(DevelopmentDnsMappingEnactor::class)->configDir()."/{$tld}.conf";

        if (! File::exists($path)) {
            return null;
        }

        $actualTarget = $this->developmentDnsTargetFrom(File::get($path), $tld);

        if ($actualTarget === $target) {
            return null;
        }

        return $this->failCommand(
            code: 'node.incompatible',
            message: "Development TLD '{$tld}' is already mapped to another gateway development DNS target.",
            meta: [
                'field' => 'tld',
                'value' => $tld,
                'target' => $target,
                'actual_target' => $actualTarget,
                'path' => $path,
            ],
        );
    }

    private function developmentDnsTargetFrom(string $content, string $tld): ?string
    {
        if (preg_match('/^address=\/(?:\\.)?'.preg_quote($tld, '/').'\/(.+)$/m', $content, $matches) !== 1) {
            return null;
        }

        return trim($matches[1]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function jsonSuccess(array $data, array $meta = []): int
    {
        $response = [
            'success' => [
                'data' => $data,
            ],
        ];

        if ($meta !== []) {
            $response['success']['meta'] = $meta;
        }

        $this->line(json_encode($response, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function failCommand(string $code, string $message, array $meta): int
    {
        if ($this->wantsJson()) {
            $this->line(json_encode([
                'error' => [
                    'code' => $code,
                    'message' => $message,
                    'meta' => $meta,
                ],
            ], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $this->error($message);

        return self::FAILURE;
    }

    private function wantsJson(): bool
    {
        return (bool) $this->option('json');
    }

    private function argument(string $key): mixed
    {
        return $this->arguments[$key] ?? null;
    }

    private function option(string $key): mixed
    {
        return $this->arguments["--{$key}"] ?? null;
    }

    private function line(string $message): void
    {
        $this->output = $message;
    }

    private function error(string $message): void
    {
        $this->output = $message;
    }

    private function info(string $message): void
    {
        $this->line($message);
    }

    private function warn(string $message): void
    {
        $this->line($message);
    }

    private function ssh(string $user, string $host, string $command, ?Node $node = null): string
    {
        if ($node instanceof Node) {
            return app(SshCommandBuilder::class)->enforceForNode(
                node: $node,
                remoteCommand: $command,
                loginUser: $user,
                options: [
                    'batch_mode' => true,
                    'prefer_public_host' => true,
                ],
            );
        }

        return app(SshCommandBuilder::class)->ssh(
            user: $user,
            host: $host,
            remoteCommand: $command,
            options: ['batch_mode' => true],
        );
    }
}
