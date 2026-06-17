<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Enums\Nodes\NodeStatus;
use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Models\App;
use App\Models\Node;
use App\Services\Apps\AppRegistrar;
use App\Services\Nodes\Access\AuthorizationResult;
use App\Services\Nodes\Access\NodeAccessAuthorizer;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[RequiresPermission('app:register', servingNode: ServingNode::Target)]
final class AppRegisterController implements Loggable
{
    private ?App $activitySubject = null;

    public function __construct(
        private readonly NodeRoleAssignments $nodeRoleAssignments,
        private readonly NodeAccessAuthorizer $authorizer,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $targetNode = $this->targetNode($request);

        if ($targetNode instanceof Node) {
            /** @var mixed $caller */
            $caller = $request->user();

            if (! $caller instanceof Node) {
                return $this->error('authorization_failed', 'Peer identity unknown.', [], 403);
            }

            $authorization = $this->authorizer->authorize($caller, $targetNode, 'app:register');

            if (! $authorization->allowed) {
                return $this->forbidden($targetNode, $authorization, 'app:register');
            }
        }

        $arguments = [
            'name' => $this->optionalString($request, 'name'),
            '--json' => true,
        ];

        $this->addStringOption($arguments, '--node', $request, 'node');
        $this->addStringOption($arguments, '--path', $request, 'path');
        $this->addStringOption($arguments, '--root', $request, 'root');
        $this->addStringOption($arguments, '--php-version', $request, 'php_version');
        $this->addStringOption($arguments, '--domain', $request, 'domain');

        $result = app(AppRegistrar::class)->register($arguments);

        $name = $this->optionalString($request, 'name');
        $this->activitySubject = $name === null
            ? null
            : App::query()->where('name', $name)->first();

        return response()->json($result->payload, $result->successful() ? 200 : 422);
    }

    private function targetNode(Request $request): ?Node
    {
        $nodeName = $this->optionalString($request, 'node');

        if ($nodeName !== null) {
            return Node::query()->where('name', $nodeName)->first();
        }

        $name = $this->optionalString($request, 'name');

        if ($name === null) {
            return null;
        }

        $existingNode = App::query()
            ->with('node')
            ->where('name', $name)
            ->first()
            ?->node;

        if ($existingNode instanceof Node) {
            return $existingNode;
        }

        return $this->singleActiveAppHostNode();
    }

    private function singleActiveAppHostNode(): ?Node
    {
        $nodes = Node::query()
            ->whereIn('id', $this->nodeRoleAssignments->activeAppHostNodeIds())
            ->where('status', NodeStatus::Active->value)
            ->orderBy('name')
            ->limit(2)
            ->get();

        if ($nodes->count() !== 1) {
            return null;
        }

        return $nodes->first();
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function addStringOption(array &$arguments, string $option, Request $request, string $key): void
    {
        $value = $this->optionalString($request, $key);

        if ($value === null) {
            return;
        }

        $arguments[$option] = $value;
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

    private function forbidden(Node $servingNode, AuthorizationResult $result, string $permission): JsonResponse
    {
        return $this->error(
            'authorization_failed',
            "This node is not authorized for '{$permission}' on '{$servingNode->name}'.",
            [
                'reason' => $result->reason,
                'missing_permission' => $result->missingPermission,
                'serving_node' => $servingNode->name,
            ],
            403,
        );
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
        return 'api:POST /apps/register';
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

    /**
     * @return array<string, mixed>
     */
    public function properties(): array
    {
        return [
            'name' => $this->optionalString(request(), 'name'),
            'node' => $this->optionalString(request(), 'node'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function activityLogProperties(): array
    {
        return $this->properties();
    }

    public function description(): ?string
    {
        return null;
    }

    public function activityLogDescription(): ?string
    {
        return $this->description();
    }
}
