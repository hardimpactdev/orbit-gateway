<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Processes\StopProcesses;
use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Http\Gateway\GatewayApiException;
use App\Models\Node;
use App\Services\Nodes\Access\NodeAccessAuthorizer;
use App\Services\Processes\ProcessOwnerContextResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[RequiresPermission('process:stop', servingNode: ServingNode::AppOwning)]
final class ProcessStopController implements Loggable
{
    private ?Model $activitySubject = null;

    public function __construct(
        private readonly NodeAccessAuthorizer $authorizer,
        private readonly ProcessOwnerContextResolver $contexts,
    ) {}

    public function __invoke(Request $request, StopProcesses $stopProcesses): JsonResponse
    {
        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return $this->error('authorization_failed', 'Peer identity unknown.', [], [], 403);
        }

        try {
            $context = $this->contexts->resolve(
                nodeName: $this->optionalString($request, 'node'),
                appName: $this->optionalString($request, 'app'),
                workspaceName: $this->optionalString($request, 'workspace'),
            );
        } catch (GatewayApiException $e) {
            return $this->error($e->errorCode() ?? 'validation_failed', $e->getMessage(), $e->errorMeta(), $e->errorData(), $this->statusFor($e));
        }

        $authorization = $this->authorizeProcessAccess($caller, $context->node, 'process:stop');

        if ($authorization instanceof JsonResponse) {
            return $authorization;
        }

        $name = $this->optionalString($request, 'name');

        try {
            $result = $stopProcesses->handle($context, $name);
        } catch (GatewayApiException $e) {
            return $this->error($e->errorCode() ?? 'validation_failed', $e->getMessage(), $e->errorMeta(), $e->errorData(), $this->statusFor($e));
        }

        $this->activitySubject = $context->subject();

        if ($result['failed']) {
            return $this->error('process.runtime_action_failed', $result['message'], $result['meta'], $result['data'], 422);
        }

        return response()->json([
            'success' => [
                'data' => $result['data'],
                'meta' => (object) [],
            ],
        ]);
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
        ], [], 403);
    }

    private function optionalString(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function statusFor(GatewayApiException $exception): int
    {
        return match ($exception->errorCode()) {
            'process.not_found' => 404,
            'authorization_failed' => 403,
            default => 422,
        };
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $data
     */
    private function error(string $code, string $message, array $meta, array $data, int $status): JsonResponse
    {
        $error = [
            'code' => $code,
            'message' => $message,
        ];

        if ($data !== []) {
            $error['data'] = $data;
        }

        $error['meta'] = empty($meta) ? (object) [] : $meta;

        return response()->json(['error' => $error], $status);
    }

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Write;
    }

    public function type(): string
    {
        return 'api:POST /processes/stop';
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
            'node' => $this->optionalString(request(), 'node'),
            'app' => $this->optionalString(request(), 'app'),
            'workspace' => $this->optionalString(request(), 'workspace'),
            'name' => $this->optionalString(request(), 'name'),
        ];
    }

    public function description(): ?string
    {
        return null;
    }
}
