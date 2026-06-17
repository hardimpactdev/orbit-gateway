<?php

declare(strict_types=1);

namespace App\Services\Schedules;

use App\Enums\Nodes\NodeStatus;
use App\Http\Gateway\GatewayApiException;
use App\Models\Node;
use App\Models\Schedule;
use App\Services\Nodes\Access\NodeAccessAuthorizer;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use Illuminate\Database\Eloquent\Builder;

class SchedulePayload
{
    /**
     * @return array{schedules: list<array<string, mixed>>, meta: array{app: string|null, node: string|null, count: int}}
     */
    public function list(?string $app, ?string $node, ?Node $caller = null): array
    {
        $this->ensureExclusiveFilters($app, $node);

        $visibleNodeIds = $this->visibleNodeIds($caller, 'schedule:read');

        if ($caller instanceof Node && ! app(NodeRoleAssignments::class)->nodeIsGateway($caller) && $visibleNodeIds === []) {
            throw new GatewayApiException('This node is not authorized to read schedule intent.', 'authorization_failed', [
                'reason' => 'missing_permission',
                'missing_permission' => 'schedule:read',
            ]);
        }

        $query = $this->visibleSchedules($caller, $visibleNodeIds)
            ->when($app !== null, fn (Builder $query): Builder => $query->where('scope', 'app')->whereHas('app', fn (Builder $query): Builder => $query->where('name', $app)))
            ->when($node !== null, fn (Builder $query): Builder => $query->where('scope', 'node')->whereHas('node', fn (Builder $query): Builder => $query->where('name', $node)))
            ->orderBy('scope')
            ->orderBy('target_name')
            ->orderBy('name');

        $schedules = $query->get()
            ->map(fn (Schedule $schedule): array => $this->serialize($schedule))
            ->values()
            ->all();

        return [
            'schedules' => $schedules,
            'meta' => [
                'app' => $app,
                'node' => $node,
                'count' => count($schedules),
            ],
        ];
    }

    /**
     * @return array{schedule: array<string, mixed>, meta: array{app: string|null, node: string|null}}
     */
    public function show(string $name, ?string $app, ?string $node, ?Node $caller = null): array
    {
        $schedule = $this->find($name, $app, $node, $caller);

        return [
            'schedule' => $this->serialize($schedule),
            'meta' => [
                'app' => $app,
                'node' => $node,
            ],
        ];
    }

    public function find(string $name, ?string $app, ?string $node, ?Node $caller = null, string $permission = 'schedule:read'): Schedule
    {
        $this->ensureExclusiveFilters($app, $node);

        $visibleNodeIds = $this->visibleNodeIds($caller, $permission);

        if ($caller instanceof Node && ! app(NodeRoleAssignments::class)->nodeIsGateway($caller) && $visibleNodeIds === []) {
            throw new GatewayApiException('This node is not authorized to read schedule intent.', 'authorization_failed', [
                'reason' => 'missing_permission',
                'missing_permission' => $permission,
            ]);
        }

        $schedule = $this->visibleSchedules($caller, $visibleNodeIds)
            ->where('name', $name)
            ->when($app !== null, fn (Builder $query): Builder => $query->where('scope', 'app')->whereHas('app', fn (Builder $query): Builder => $query->where('name', $app)))
            ->when($node !== null, fn (Builder $query): Builder => $query->where('scope', 'node')->whereHas('node', fn (Builder $query): Builder => $query->where('name', $node)))
            ->orderBy('scope')
            ->orderBy('target_name')
            ->first();

        if (! $schedule instanceof Schedule) {
            throw new GatewayApiException("Schedule '{$name}' was not found.", 'schedule.not_found', [
                'name' => $name,
                'app' => $app,
                'node' => $node,
            ]);
        }

        return $schedule;
    }

    private function ensureExclusiveFilters(?string $app, ?string $node): void
    {
        if ($app === null || $node === null) {
            return;
        }

        throw new GatewayApiException('The schedule filters are mutually exclusive.', 'validation_failed', [
            'fields' => ['app', 'node'],
        ]);
    }

    /**
     * @param  list<int>|null  $visibleNodeIds
     * @return Builder<Schedule>
     */
    private function visibleSchedules(?Node $caller, ?array $visibleNodeIds): Builder
    {
        $canSeeOrbitSchedules = $visibleNodeIds !== null
            && array_intersect($visibleNodeIds, $this->gatewayNodeIds()) !== [];

        return Schedule::query()
            ->with(['app.node', 'node', 'latestRun'])
            ->when($caller instanceof Node && ! app(NodeRoleAssignments::class)->nodeIsGateway($caller), fn (Builder $query): Builder => $query->where(function (Builder $query) use ($visibleNodeIds, $canSeeOrbitSchedules): void {
                $query
                    ->whereIn('node_id', $visibleNodeIds ?? [])
                    ->orWhereHas('app', fn (Builder $query): Builder => $query->whereIn('node_id', $visibleNodeIds ?? []));

                if ($canSeeOrbitSchedules) {
                    $query->orWhere('scope', 'orbit');
                }
            }));
    }

    /**
     * @return list<int>|null
     */
    private function visibleNodeIds(?Node $caller, string $permission): ?array
    {
        if (! $caller instanceof Node || app(NodeRoleAssignments::class)->nodeIsGateway($caller)) {
            return null;
        }

        $authorizer = app(NodeAccessAuthorizer::class);
        $nodes = Node::query()
            ->where('status', NodeStatus::Active->value)
            ->where(function (Builder $query): void {
                $query
                    ->whereIn('id', app(NodeRoleAssignments::class)->activeGatewayOrAppHostNodeIds());
            })
            ->get();

        $visibleNodeIds = [];

        foreach ($nodes as $node) {
            if ($authorizer->allows($caller, $node, $permission)) {
                $visibleNodeIds[] = $node->id;
            }
        }

        return $visibleNodeIds;
    }

    /**
     * @return list<int>
     */
    private function gatewayNodeIds(): array
    {
        return app(NodeRoleAssignments::class)
            ->activeGatewayNodeQuery()
            ->pluck('id')
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function forSchedule(Schedule $schedule): array
    {
        $schedule->loadMissing(['app.node', 'node', 'latestRun']);

        return $this->serialize($schedule);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(Schedule $schedule): array
    {
        $gatewayNode = $this->gatewayNode();
        $targetNode = match ($schedule->scope) {
            'app' => $schedule->app?->node,
            'node' => $schedule->node,
            'orbit' => $gatewayNode,
            default => null,
        };

        return [
            'name' => $schedule->name,
            'scope' => $schedule->scope,
            'target' => [
                'type' => $schedule->scope,
                'name' => $schedule->target_name,
                'node' => $targetNode?->name,
            ],
            'interval' => $schedule->interval,
            'timezone' => $schedule->timezone,
            'execution' => [
                'type' => $schedule->execution_type,
                'value' => $schedule->execution_value,
            ],
            'enabled' => $schedule->enabled,
            'status' => $schedule->status,
            'scheduler' => [
                'node' => $gatewayNode?->name,
                'heartbeat_at' => $gatewayNode?->schedulerState?->heartbeat_at?->toIso8601String(),
                'registry_synced_at' => $gatewayNode?->schedulerState?->registry_synced_at?->toIso8601String(),
            ],
            'last_run' => $schedule->latestRun === null ? null : [
                'id' => $schedule->latestRun->id,
                'status' => $schedule->latestRun->status,
                'exit_code' => $schedule->latestRun->exit_code,
                'started_at' => $schedule->latestRun->started_at->toIso8601String(),
                'finished_at' => $schedule->latestRun->finished_at?->toIso8601String(),
            ],
        ];
    }

    private function gatewayNode(): ?Node
    {
        return app(NodeRoleAssignments::class)
            ->activeGatewayNodeQuery()
            ->with('schedulerState')
            ->first();
    }
}
