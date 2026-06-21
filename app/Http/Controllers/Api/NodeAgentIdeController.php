<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Enums\Nodes\NodeStatus;
use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Http\Requests\Api\SetNodeAgentIdeApiRequest;
use App\Models\Node;
use App\Services\Nodes\NodeAgentIdeDefaults;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;

#[RequiresPermission('node:agent', servingNode: ServingNode::Target)]
final class NodeAgentIdeController implements Loggable
{
    private ?Node $activitySubject = null;

    private ?string $activityTargetName = null;

    private ?string $activityAgentIde = null;

    private ?string $activityAction = null;

    public function __construct(
        private readonly NodeAgentIdeDefaults $defaults,
    ) {}

    public function __invoke(SetNodeAgentIdeApiRequest $request, string $name): JsonResponse
    {
        $this->activityTargetName = $name;

        /** @var mixed $resolvedUser */
        $resolvedUser = $request->user();
        $caller = $resolvedUser instanceof Node ? $resolvedUser : null;

        if (! $caller instanceof Node) {
            return $this->authorizationFailed('Peer identity unknown.');
        }

        $node = Node::query()
            ->where('name', $name)
            ->where('status', NodeStatus::Active->value)
            ->first();

        if (! $node instanceof Node) {
            return $this->error(
                code: 'node.not_found',
                message: "Node '{$name}' not found.",
                meta: ['name' => $name],
                status: 404,
            );
        }

        $this->activitySubject = $node;

        $agentIde = $request->agentIde();

        if (! $this->defaults->isSupported($agentIde)) {
            return $this->error(
                code: 'node.unsupported_adapter',
                message: "Adapter '{$agentIde}' is not supported.",
                meta: ['adapter' => $agentIde],
                status: 422,
            );
        }

        $data = $this->defaults->set($node, $agentIde);
        $this->activityAgentIde = $data['agent_ide']['adapter'];
        $this->activityAction = $data['action'];

        return response()->json([
            'success' => [
                'data' => $data,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function authorizationFailed(string $message, array $meta = []): JsonResponse
    {
        return $this->error(
            code: 'authorization_failed',
            message: $message,
            meta: $meta,
            status: 403,
        );
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

    public function activityLogType(): ActivityLogType
    {
        return $this->effect();
    }

    public function type(): string
    {
        return 'api:POST /nodes/{name}/agent-ide';
    }

    public function activityLogAction(): string
    {
        return $this->type();
    }

    public function subject(): ?Model
    {
        return $this->activitySubject;
    }

    public function activityLogSubject(): ?Model
    {
        return $this->subject();
    }

    public function properties(): array
    {
        return [
            'target_node' => $this->activityTargetName ?? (string) request()->route('name'),
            'agent_ide' => $this->activityAgentIde,
            'action' => $this->activityAction,
        ];
    }

    public function activityLogProperties(): array
    {
        return $this->properties();
    }

    public function description(): ?string
    {
        $target = $this->activityTargetName ?? (string) request()->route('name');

        if ($target === '' || $this->activityAction === null) {
            return null;
        }

        if ($this->activityAgentIde === null) {
            return "Node {$target} agent IDE cleared";
        }

        if ($this->activityAction === 'converged') {
            return "Node {$target} agent IDE already set to {$this->activityAgentIde}";
        }

        return "Node {$target} agent IDE set to {$this->activityAgentIde}";
    }

    public function activityLogDescription(): ?string
    {
        return $this->description();
    }
}
