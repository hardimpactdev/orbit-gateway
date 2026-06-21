<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Enums\Processes\ProcessRuntime;
use App\Http\Gateway\GatewayApiException;
use App\Models\App;
use App\Models\Node;
use App\Models\Process;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

final readonly class ProcessOwnerContext
{
    public function __construct(
        public Node $node,
        public ?App $app,
        public ?Workspace $workspace,
        public Model $owner,
    ) {}

    public function runtimeApp(): App
    {
        if ($this->app instanceof App) {
            $this->app->setRelation('node', $this->node);

            return $this->app;
        }

        $home = ($this->node->user ?: 'orbit') === 'root'
            ? '/root'
            : '/home/'.($this->node->user ?: 'orbit');

        $app = new App([
            'name' => $this->node->name,
            'path' => $home,
            'node_id' => $this->node->id,
        ]);
        $app->setRelation('node', $this->node);

        return $app;
    }

    public function defaultRuntime(): ProcessRuntime
    {
        if ($this->app instanceof App) {
            return ProcessRuntime::defaultForApp($this->app);
        }

        return ProcessRuntime::Systemd;
    }

    public function allowsRuntime(ProcessRuntime $runtime): bool
    {
        if ($this->owner instanceof Node) {
            return true;
        }

        return $runtime->appWorkspaceCommandViolationReason() === null;
    }

    public function assertRuntimeAllowed(ProcessRuntime $runtime): void
    {
        if ($this->allowsRuntime($runtime)) {
            return;
        }

        throw new GatewayApiException($runtime->appWorkspaceCommandViolationMessage() ?? 'The selected runtime is not valid for this process owner.', 'validation_failed', [
            'field' => 'runtime',
            'value' => $runtime->value,
            'reason' => $runtime->appWorkspaceCommandViolationReason(),
        ]);
    }

    /**
     * @return MorphMany<Process, Node>|MorphMany<Process, App>|MorphMany<Process, Workspace>
     */
    public function ownerProcesses(): MorphMany
    {
        if ($this->owner instanceof Node || $this->owner instanceof App || $this->owner instanceof Workspace) {
            return $this->owner->processes();
        }

        throw new GatewayApiException('Process owner is not lifecycle-addressable.', 'validation_failed', [
            'field' => 'context',
        ]);
    }

    /**
     * @return Collection<int, Process>
     */
    public function lifecycleProcesses(?string $name): Collection
    {
        if ($this->workspace instanceof Workspace && $this->app instanceof App) {
            /** @var Collection<int, Process> $appProcesses */
            $appProcesses = $this->app->processes()
                ->when($name !== null, fn ($query) => $query->where('name', $name))
                ->get();

            /** @var Collection<int, Process> $workspaceProcesses */
            $workspaceProcesses = $this->workspace->processes()
                ->when($name !== null, fn ($query) => $query->where('name', $name))
                ->get();

            /** @var Collection<int, Process> $processes */
            $processes = new Collection($appProcesses
                ->concat($workspaceProcesses)
                ->sortBy([
                    ['sort_order', 'asc'],
                    ['id', 'asc'],
                ])
                ->values()
                ->all());

            return $processes;
        }

        /** @var Collection<int, Process> $processes */
        $processes = $this->ownerProcesses()
            ->when($name !== null, fn ($query) => $query->where('name', $name))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return $processes;
    }

    public function runtimeWorkspaceFor(Process $process): ?Workspace
    {
        if (! $this->workspace instanceof Workspace) {
            return null;
        }

        return $this->workspace;
    }

    public function eventApp(): ?App
    {
        return $this->app;
    }

    public function subject(): Model
    {
        return $this->app ?? $this->node;
    }

    /**
     * @return array{node: string, app: string|null, workspace: string|null}
     */
    public function payloadContext(): array
    {
        return [
            'node' => $this->node->name,
            'app' => $this->app?->name,
            'workspace' => $this->workspace?->name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function processPayload(Process $process): array
    {
        return [
            'name' => $process->name,
            ...$this->payloadContext(),
            'command' => $process->command,
            'restart_policy' => $process->restart_policy->value,
            'crash_notification' => $process->crash_notification->value,
            'runtime' => $process->runtime->value,
            'tool' => $process->tool,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function errorMeta(?string $name = null): array
    {
        return array_filter([
            'node' => $this->node->name,
            'app' => $this->app?->name,
            'workspace' => $this->workspace?->name,
            'name' => $name,
        ], fn (mixed $value): bool => $value !== null);
    }

    public function label(): string
    {
        if ($this->workspace instanceof Workspace && $this->app instanceof App) {
            return "workspace '{$this->workspace->name}' on app '{$this->app->name}'";
        }

        if ($this->app instanceof App) {
            return "app '{$this->app->name}'";
        }

        return "node '{$this->node->name}'";
    }
}
