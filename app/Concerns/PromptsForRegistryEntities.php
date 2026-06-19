<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeStatus;
use App\Exceptions\PromptAborted;
use App\Http\Gateway\GatewayApiException;
use App\Models\App;
use App\Models\Node;
use App\Models\Schedule;
use App\Models\Workspace;
use App\Services\Nodes\Roles\NodeRoleAssignmentPayload;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use Illuminate\Database\Eloquent\Builder;

trait PromptsForRegistryEntities
{
    use HandlesPromptCancellation;

    private const string WORKSPACE_SELECTION_SEPARATOR = "\t";

    private const string SCHEDULE_SELECTION_SEPARATOR = "\t";

    /**
     * @throws PromptAborted
     */
    protected function promptForVisibleApp(string $label = 'Select an app', ?string $node = null, ?string $environment = null): string|GatewayApiException
    {
        $apps = $this->visibleAppPromptPayloads($node, $environment);

        if ($apps instanceof GatewayApiException) {
            return $apps;
        }

        if ($apps === []) {
            return new GatewayApiException('No apps found.', 'app.not_found', [
                'field' => 'app',
            ]);
        }

        return (string) $this->promptDataTable(
            label: $label,
            headers: ['App', 'Host', 'Node', 'Repository'],
            rows: $this->appPromptRows($apps),
        );
    }

    /**
     * @throws PromptAborted
     */
    protected function promptForVisibleNode(
        string $label = 'Select a node',
        ?string $role = null,
        ?string $environment = null,
        bool $activeOnly = true,
        ?string $preferred = null,
    ): string|GatewayApiException {
        $nodes = $this->visibleNodePromptPayloads($role, $environment, $activeOnly);

        if ($nodes instanceof GatewayApiException) {
            return $nodes;
        }

        if ($nodes === []) {
            return new GatewayApiException('No nodes found.', 'node.not_found', [
                'field' => 'name',
            ]);
        }

        return (string) $this->promptDataTable(
            label: $label,
            headers: ['Node', 'Roles', 'Host', 'Status'],
            rows: $this->nodePromptRows($this->preferNodePromptPayload($nodes, $preferred)),
        );
    }

    /**
     * @return array{name: string, app: string|null}|GatewayApiException
     *
     * @throws PromptAborted
     */
    protected function promptForVisibleWorkspace(string $label = 'Select a workspace', ?string $app = null, ?string $node = null): array|GatewayApiException
    {
        $workspaces = $this->visibleWorkspacePromptPayloads($app, $node);

        if ($workspaces instanceof GatewayApiException) {
            return $workspaces;
        }

        if ($workspaces === []) {
            return new GatewayApiException('No workspaces found.', 'workspace.not_found', [
                'field' => 'name',
            ]);
        }

        return $this->promptForWorkspacePayloads($label, $workspaces);
    }

    /**
     * @return array{name: string, app: string|null, node: string|null}|GatewayApiException
     *
     * @throws PromptAborted
     */
    protected function promptForVisibleSchedule(string $label = 'Select a schedule', ?string $app = null, ?string $node = null): array|GatewayApiException
    {
        $schedules = $this->visibleSchedulePromptPayloads($app, $node);

        if ($schedules instanceof GatewayApiException) {
            return $schedules;
        }

        if ($schedules === []) {
            return new GatewayApiException('No schedules found.', 'schedule.not_found', [
                'field' => 'name',
                'app' => $app,
                'node' => $node,
            ]);
        }

        $selected = (string) $this->promptDataTable(
            label: $label,
            headers: ['Schedule', 'Scope', 'Target', 'Node', 'Interval', 'Execution', 'Status'],
            rows: $this->schedulePromptRows($schedules),
        );

        return $this->decodeScheduleSelection($selected);
    }

    /**
     * @param  list<array<string, mixed>>  $workspaces
     * @return array{name: string, app: string|null}
     *
     * @throws PromptAborted
     */
    protected function promptForWorkspacePayloads(string $label, array $workspaces): array
    {
        $selected = (string) $this->promptDataTable(
            label: $label,
            headers: ['Workspace', 'App', 'Node', 'URL', 'Status'],
            rows: $this->workspacePromptRows($workspaces),
        );

        return $this->decodeWorkspaceSelection($selected);
    }

    /**
     * @return list<array<string, mixed>>|GatewayApiException
     */
    protected function visibleAppPromptPayloads(?string $node = null, ?string $environment = null): array|GatewayApiException
    {
        return App::query()
            ->with('node')
            ->when($node !== null, fn (Builder $query): Builder => $query->whereHas('node', fn (Builder $query): Builder => $query->where('name', $node)))
            ->when($environment !== null, fn (Builder $query): Builder => $query->where('environment', $environment))
            ->orderBy('name')
            ->get()
            ->map(fn (App $app): array => $this->appPromptPayload($app))
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>|GatewayApiException
     */
    protected function visibleNodePromptPayloads(?string $role = null, ?string $environment = null, bool $activeOnly = true): array|GatewayApiException
    {
        $query = Node::query()
            ->with('roleAssignments')
            ->when($activeOnly, fn (Builder $query): Builder => $query->where('status', NodeStatus::Active->value))
            ->orderBy('name');

        $this->applyNodePromptRoleFilter($query, $role, $environment);

        return $query->get()
            ->sortBy(fn (Node $node): string => $this->nodePromptRolesLabel($this->nodePromptPayload($node)).' '.$node->name)
            ->map(fn (Node $node): array => $this->nodePromptPayload($node))
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>|GatewayApiException
     */
    protected function visibleWorkspacePromptPayloads(?string $app = null, ?string $node = null): array|GatewayApiException
    {
        return Workspace::query()
            ->with('app.node')
            ->when($app !== null, fn (Builder $query): Builder => $query->whereHas('app', fn (Builder $query): Builder => $query->where('name', $app)))
            ->when($node !== null, fn (Builder $query): Builder => $query->whereHas('app.node', fn (Builder $query): Builder => $query->where('name', $node)))
            ->get()
            ->sort(fn (Workspace $first, Workspace $second): int => [
                mb_strtolower((string) $first->app?->node?->name),
                mb_strtolower((string) $first->app?->name),
                mb_strtolower($first->name),
            ] <=> [
                mb_strtolower((string) $second->app?->node?->name),
                mb_strtolower((string) $second->app?->name),
                mb_strtolower($second->name),
            ])
            ->map(fn (Workspace $workspace): array => $this->workspacePromptPayload($workspace))
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>|GatewayApiException
     */
    protected function visibleSchedulePromptPayloads(?string $app = null, ?string $node = null): array|GatewayApiException
    {
        return Schedule::query()
            ->with(['app.node', 'node'])
            ->when($app !== null, fn (Builder $query): Builder => $query->where('scope', 'app')->whereHas('app', fn (Builder $query): Builder => $query->where('name', $app)))
            ->when($node !== null, fn (Builder $query): Builder => $query->where('scope', 'node')->whereHas('node', fn (Builder $query): Builder => $query->where('name', $node)))
            ->orderBy('scope')
            ->orderBy('target_name')
            ->orderBy('name')
            ->get()
            ->map(fn (Schedule $schedule): array => $this->schedulePromptPayload($schedule))
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $apps
     * @return array<string, array<int, string>>
     */
    protected function appPromptRows(array $apps): array
    {
        $rows = [];

        foreach ($apps as $app) {
            $name = $this->promptString($app['name'] ?? null);

            if ($name === '') {
                continue;
            }

            $rows[$name] = [
                $name,
                $this->appHostFromPromptPayload($app),
                $this->promptString($app['node'] ?? null, '-'),
                $this->promptString($app['repository'] ?? null, '-'),
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @return array<string, array<int, string>>
     */
    protected function nodePromptRows(array $nodes): array
    {
        $rows = [];

        foreach ($nodes as $node) {
            $name = $this->promptString($node['name'] ?? null);

            if ($name === '') {
                continue;
            }

            $rows[$name] = [
                $name,
                $this->nodePromptRolesLabel($node),
                $this->promptString($node['host'] ?? $node['wireguard_address'] ?? null, '-'),
                $this->promptString($node['status'] ?? null, '-'),
            ];
        }

        return $rows;
    }

    /**
     * @param  Builder<Node>  $query
     */
    private function applyNodePromptRoleFilter(Builder $query, ?string $role, ?string $environment): void
    {
        $assignments = app(NodeRoleAssignments::class);
        $role = $this->nodePromptRoleForEnvironment($role, $environment);

        if ($role === null) {
            return;
        }

        if ($role === 'app-host') {
            $query->whereIn('id', $assignments->activeAppHostNodeIds());

            return;
        }

        $query->whereIn('id', $assignments->activeNodeIdsForRole($role));
    }

    private function nodePromptGatewayRoleFilter(?string $role, ?string $environment): ?string
    {
        $role = $this->nodePromptRoleForEnvironment($role, $environment);

        return $role === 'app-host' ? null : $role;
    }

    private function nodePromptRoleForEnvironment(?string $role, ?string $environment): ?string
    {
        if ($environment === 'development') {
            return NodeRoleName::AppDevelopment->value;
        }

        if ($environment === 'production') {
            return NodeRoleName::AppProduction->value;
        }

        return $role;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function nodePromptMatchesRoleFilter(array $node, ?string $role, ?string $environment): bool
    {
        $role = $this->nodePromptRoleForEnvironment($role, $environment);

        if ($role === null) {
            return true;
        }

        $roles = $node['roles'] ?? [];

        if (! is_array($roles)) {
            return false;
        }

        if ($role === 'app-host') {
            return collect($roles)->contains(fn (mixed $assignment): bool => is_array($assignment)
                && in_array($assignment['role'] ?? null, [NodeRoleName::AppDevelopment->value, NodeRoleName::AppProduction->value], true)
                && ($assignment['status'] ?? null) === 'active');
        }

        return collect($roles)->contains(fn (mixed $assignment): bool => is_array($assignment)
            && ($assignment['role'] ?? null) === $role
            && ($assignment['status'] ?? null) === 'active');
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function nodePromptRolesLabel(array $node): string
    {
        $roles = $node['roles'] ?? null;

        if (! is_array($roles) || $roles === []) {
            return '-';
        }

        $labels = [];

        foreach ($roles as $role) {
            if (! is_array($role) || ! is_string($role['role'] ?? null) || $role['role'] === '') {
                continue;
            }

            $status = is_string($role['status'] ?? null) ? $role['status'] : 'active';
            $labels[] = $status === 'active' ? $role['role'] : "{$role['role']} ({$status})";
        }

        return $labels === [] ? '-' : implode(', ', $labels);
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @return list<array<string, mixed>>
     */
    private function preferNodePromptPayload(array $nodes, ?string $preferred): array
    {
        if ($preferred === null || $preferred === '') {
            return $nodes;
        }

        $preferredNodes = [];
        $otherNodes = [];

        foreach ($nodes as $node) {
            if ($this->promptString($node['name'] ?? null) === $preferred) {
                $preferredNodes[] = $node;

                continue;
            }

            $otherNodes[] = $node;
        }

        return [...$preferredNodes, ...$otherNodes];
    }

    /**
     * @param  list<array<string, mixed>>  $workspaces
     * @return array<string, array<int, string>>
     */
    protected function workspacePromptRows(array $workspaces): array
    {
        $rows = [];

        foreach ($workspaces as $workspace) {
            $name = $this->promptString($workspace['name'] ?? null);

            if ($name === '') {
                continue;
            }

            $app = $this->promptString($workspace['app'] ?? null);
            $rows[$this->workspaceSelectionKey($name, $app)] = [
                $name,
                $app !== '' ? $app : '-',
                $this->promptString($workspace['node'] ?? null, '-'),
                $this->promptString($workspace['url'] ?? null, '-'),
                $this->promptString($workspace['lifecycle_status'] ?? $workspace['status'] ?? null, '-'),
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $schedules
     * @return array<string, array<int, string>>
     */
    protected function schedulePromptRows(array $schedules): array
    {
        $rows = [];

        foreach ($schedules as $schedule) {
            $name = $this->promptString($schedule['name'] ?? null);
            $scope = $this->promptString($schedule['scope'] ?? null);
            $target = $this->scheduleTargetName($schedule);

            if ($name === '' || $scope === '' || $target === '') {
                continue;
            }

            $rows[$this->scheduleSelectionKey($scope, $target, $name)] = [
                $name,
                $scope,
                $target,
                $this->scheduleTargetNode($schedule),
                $this->promptString($schedule['interval'] ?? null, '-'),
                $this->scheduleExecutionLabel($schedule),
                $this->promptString($schedule['status'] ?? null, '-'),
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function appPromptPayload(App $app): array
    {
        return [
            'name' => $app->name,
            'node' => $app->node?->name,
            'environment' => $app->environment,
            'url' => $app->url(),
            'repository' => $app->repository,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function nodePromptPayload(Node $node): array
    {
        return [
            'name' => $node->name,
            'roles' => $node->roleAssignments
                ->map(fn ($assignment): array => NodeRoleAssignmentPayload::fromModel($assignment))
                ->all(),
            'host' => $node->host,
            'wireguard_address' => $node->wireguard_address,
            'status' => $node->status->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function workspacePromptPayload(Workspace $workspace): array
    {
        return [
            'name' => $workspace->name,
            'app' => $workspace->app?->name,
            'node' => $workspace->app?->node?->name,
            'url' => $workspace->url(),
            'lifecycle_status' => $workspace->lifecycle_status->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function schedulePromptPayload(Schedule $schedule): array
    {
        $targetNode = $schedule->scope === 'app'
            ? $schedule->app?->node
            : $schedule->node;

        return [
            'name' => $schedule->name,
            'scope' => $schedule->scope,
            'target' => [
                'type' => $schedule->scope,
                'name' => $schedule->target_name,
                'node' => $targetNode?->name,
            ],
            'interval' => $schedule->interval,
            'execution' => [
                'type' => $schedule->execution_type,
                'value' => $schedule->execution_value,
            ],
            'status' => $schedule->status,
        ];
    }

    /**
     * @param  array<string, mixed>  $app
     */
    private function appHostFromPromptPayload(array $app): string
    {
        $host = $this->promptString($app['domain'] ?? null);

        if ($host !== '') {
            return $host;
        }

        $url = $this->promptString($app['url'] ?? null);

        if ($url === '') {
            return $this->promptString($app['host'] ?? null, '-');
        }

        $parsed = parse_url((string) $url, PHP_URL_HOST);

        return is_string($parsed) && $parsed !== '' ? $parsed : $url;
    }

    private function workspaceSelectionKey(string $name, string $app): string
    {
        return $app.self::WORKSPACE_SELECTION_SEPARATOR.$name;
    }

    /**
     * @return array{name: string, app: string|null}
     */
    private function decodeWorkspaceSelection(string $selected): array
    {
        [$app, $name] = array_pad(explode(self::WORKSPACE_SELECTION_SEPARATOR, $selected, 2), 2, '');

        return [
            'name' => $name,
            'app' => $app !== '' ? $app : null,
        ];
    }

    private function scheduleSelectionKey(string $scope, string $target, string $name): string
    {
        return $scope.self::SCHEDULE_SELECTION_SEPARATOR.$target.self::SCHEDULE_SELECTION_SEPARATOR.$name;
    }

    /**
     * @return array{name: string, app: string|null, node: string|null}
     */
    private function decodeScheduleSelection(string $selected): array
    {
        [$scope, $target, $name] = array_pad(explode(self::SCHEDULE_SELECTION_SEPARATOR, $selected, 3), 3, '');

        return [
            'name' => $name,
            'app' => $scope === 'app' && $target !== '' ? $target : null,
            'node' => $scope === 'node' && $target !== '' ? $target : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $schedule
     */
    private function scheduleTargetName(array $schedule): string
    {
        $target = $schedule['target'] ?? [];

        return is_array($target) ? $this->promptString($target['name'] ?? null) : '';
    }

    /**
     * @param  array<string, mixed>  $schedule
     */
    private function scheduleTargetNode(array $schedule): string
    {
        $target = $schedule['target'] ?? [];

        return is_array($target) ? $this->promptString($target['node'] ?? null, '-') : '-';
    }

    /**
     * @param  array<string, mixed>  $schedule
     */
    private function scheduleExecutionLabel(array $schedule): string
    {
        $execution = $schedule['execution'] ?? [];

        if (! is_array($execution)) {
            return '-';
        }

        $type = $this->promptString($execution['type'] ?? null);
        $value = $this->promptString($execution['value'] ?? null);

        if ($type === '') {
            return $value !== '' ? $value : '-';
        }

        return $value !== '' ? "{$type}: {$value}" : $type;
    }

    private function promptString(mixed $value, string $fallback = ''): string
    {
        return is_string($value) && $value !== '' ? $value : $fallback;
    }
}
