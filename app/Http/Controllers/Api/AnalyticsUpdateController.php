<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeStatus;
use App\Models\Node;
use App\Models\Process;
use App\Services\Nodes\Access\NodeAccessAuthorizer;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AnalyticsUpdateController implements Loggable
{
    private const string ProcessName = 'plausible';

    private const string ProcessDefinition = 'plausible';

    private const string ImageRepository = 'ghcr.io/plausible/community-edition';

    private ?Node $activitySubject = null;

    private ?string $activityNodeName = null;

    private ?string $activityPreviousVersion = null;

    private ?string $activityVersion = null;

    public function __construct(
        private readonly NodeAccessAuthorizer $authorizer,
        private readonly NodeRoleAssignments $roleAssignments,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return $this->error('authorization_failed', 'Peer identity unknown.', [], 403);
        }

        $version = $this->version($request);

        if ($version === null) {
            return $this->error('validation_failed', 'Plausible version is required.', [
                'field' => 'version',
            ], 422);
        }

        if (! $this->isValidVersion($version)) {
            return $this->error('validation_failed', 'Plausible version must be a semantic version string.', [
                'field' => 'version',
                'value' => $version,
            ], 422);
        }

        $node = $this->resolveAnalyticsNode($request);

        if (! $node instanceof Node) {
            return $this->error('analytics.prerequisite_failed', 'No active analytics node could be resolved.', [
                'version' => $version,
            ], 422);
        }

        $authorization = $this->authorizeProcessAccess($caller, $node, 'process:edit');

        if ($authorization instanceof JsonResponse) {
            return $authorization;
        }

        $process = $this->plausibleProcess($node);

        if (! $process instanceof Process) {
            return $this->error('process.not_found', "Process 'plausible' was not found on analytics node '{$node->name}'.", [
                'node' => $node->name,
                'process' => self::ProcessName,
                'definition' => self::ProcessDefinition,
            ], 404);
        }

        $previousVersion = $this->processVersion($process);
        $status = $previousVersion === $version ? 'unchanged' : 'updated';

        $process->runtime_config = $this->updatedRuntimeConfig($process, $version);
        $process->save();

        $this->activitySubject = $node;
        $this->activityNodeName = $node->name;
        $this->activityPreviousVersion = $previousVersion;
        $this->activityVersion = $version;

        return response()->json([
            'success' => [
                'data' => [
                    'analytics' => [
                        'node' => $node->name,
                        'process' => $process->name,
                        'previous_version' => $previousVersion,
                        'version' => $version,
                        'status' => $status,
                    ],
                ],
            ],
        ]);
    }

    private function version(Request $request): ?string
    {
        $value = $request->input('version');

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function isValidVersion(string $version): bool
    {
        return preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $version) === 1;
    }

    private function resolveAnalyticsNode(Request $request): ?Node
    {
        $nodeName = $this->optionalString($request, 'node');
        $query = Node::query()
            ->where('status', NodeStatus::Active->value)
            ->whereIn('id', $this->roleAssignments->activeNodeIdsForRole(NodeRoleName::Analytics->value));

        if ($nodeName !== null) {
            return $query->where('name', $nodeName)->first();
        }

        $nodes = $query->limit(2)->get();

        return $nodes->count() === 1 ? $nodes->first() : null;
    }

    private function authorizeProcessAccess(Node $caller, Node $node, string $permission): ?JsonResponse
    {
        $result = $this->authorizer->authorize($caller, $node, $permission);

        if ($result->allowed) {
            return null;
        }

        return $this->error('authorization_failed', "This node is not authorized for '{$permission}' on '{$node->name}'.", [
            'reason' => $result->reason,
            'missing_permission' => $result->missingPermission,
            'serving_node' => $node->name,
        ], 403);
    }

    private function plausibleProcess(Node $node): ?Process
    {
        return Process::query()
            ->where('owner_type', $node->getMorphClass())
            ->where('owner_id', $node->id)
            ->where('name', self::ProcessName)
            ->where(function (Builder $query): void {
                $query
                    ->where('runtime_config->definition', self::ProcessDefinition)
                    ->orWhereNull('runtime_config->definition');
            })
            ->first();
    }

    private function processVersion(Process $process): ?string
    {
        $config = is_array($process->runtime_config) ? $process->runtime_config : [];
        $version = $config['version'] ?? null;

        return is_string($version) && $version !== '' ? $version : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function updatedRuntimeConfig(Process $process, string $version): array
    {
        $config = is_array($process->runtime_config) ? $process->runtime_config : [];
        $config['definition'] = self::ProcessDefinition;
        $config['version_family'] = $version;
        $config['version'] = $version;
        $config['image'] = self::ImageRepository.":{$version}";
        unset($config['spec_hash']);

        $labels = is_array($config['labels'] ?? null) ? $config['labels'] : [];
        $labels['orbit.process.definition'] = self::ProcessDefinition;
        $labels['orbit.process.version_family'] = $version;
        $labels['orbit.process.version'] = $version;
        unset($labels['orbit.process.spec_hash']);
        $config['labels'] = $labels;

        $specHash = $this->specHash([
            ...$config,
            'runtime' => $process->runtime->value,
            'process' => $process->name,
        ]);

        $config['spec_hash'] = $specHash;
        $config['labels']['orbit.process.spec_hash'] = $specHash;

        return $config;
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function specHash(array $spec): string
    {
        ksort($spec);

        return substr(hash('sha256', json_encode($spec, JSON_THROW_ON_ERROR)), 0, 16);
    }

    private function optionalString(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function error(string $code, string $message, array $meta, int $status): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'meta' => $meta,
            ],
        ], $status);
    }

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Write;
    }

    public function type(): string
    {
        return 'api:POST /analytics/update';
    }

    public function subject(): ?Model
    {
        return $this->activitySubject;
    }

    /**
     * @return array<string, mixed>
     */
    public function properties(): array
    {
        return [
            'node' => $this->activityNodeName ?? $this->optionalString(request(), 'node'),
            'previous_version' => $this->activityPreviousVersion,
            'version' => $this->activityVersion ?? $this->optionalString(request(), 'version'),
        ];
    }

    public function description(): ?string
    {
        if ($this->activityNodeName === null || $this->activityVersion === null) {
            return null;
        }

        return "Updated analytics on {$this->activityNodeName} to {$this->activityVersion}";
    }
}
