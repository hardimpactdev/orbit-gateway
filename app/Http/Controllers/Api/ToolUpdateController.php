<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Http\Controllers\Api\Concerns\ResolvesVisibleToolNodes;
use App\Http\Controllers\Api\Concerns\StreamsToolActionProgress;
use App\Models\Node;
use App\Services\Nodes\Access\NodeAccessAuthorizer;
use App\Services\Tools\AgentToolAuthorizer;
use App\Services\Tools\ToolRegistryFailure;
use App\Services\Tools\ToolUpdater;
use App\Support\Streaming\ProgressEventStreamResponseFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ToolUpdateController implements Loggable
{
    use ResolvesVisibleToolNodes;
    use StreamsToolActionProgress;

    private ?Node $activitySubject = null;

    public function __invoke(
        Request $request,
        string $tool,
        ToolUpdater $updater,
        ProgressEventStreamResponseFactory $streams,
    ): JsonResponse|StreamedResponse {
        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return $this->authorizationFailed('Peer identity unknown.');
        }

        $visibleNodeIds = $this->visibleToolNodeIds($caller, false, 'tool:update');

        $isAgentSelfWithPermission = $this->isAgentSelfWithUpdatePermission($caller);

        if (! $this->nodeRoleAssignments()->nodeIsGateway($caller) && $visibleNodeIds === [] && ! $isAgentSelfWithPermission) {
            return $this->authorizationFailed('This node is not authorized to manage tools.');
        }

        $target = $this->authorizedToolTarget($request, $caller, $visibleNodeIds);

        if ($target instanceof JsonResponse) {
            $agentSelfBypass = $this->agentSelfUpdateBypass($request, $caller, $tool);

            if ($agentSelfBypass !== null) {
                return $agentSelfBypass;
            }

            return $target;
        }

        $node = $target['node'];
        $app = $target['app'];

        $agentSelfAuth = $this->authorizeAgentToolAction($caller, $node, $tool, 'update');

        if ($agentSelfAuth instanceof JsonResponse) {
            return $agentSelfAuth;
        }

        $version = $this->requestString($request, 'version');
        $instance = $this->requestString($request, 'instance');

        return $this->executeUpdate($request, $tool, $updater, $streams, $caller, $node, $app, $version, $instance);
    }

    private function isAgentSelfWithUpdatePermission(Node $caller): bool
    {
        $authorizer = app(AgentToolAuthorizer::class);

        if (! $authorizer->isAgentSelf($caller, $caller->name)) {
            return false;
        }

        return app(NodeAccessAuthorizer::class)->allows($caller, $caller, 'tool:update:agent-tools');
    }

    private function agentSelfUpdateBypass(Request $request, Node $caller, string $tool): ?JsonResponse
    {
        if (! $this->isAgentSelfWithUpdatePermission($caller)) {
            return null;
        }

        $requestedNode = $this->toolTargetString($request, 'node');
        $requestedApp = $this->toolTargetString($request, 'app');

        if ($requestedApp !== null) {
            return null;
        }

        if ($requestedNode !== null && $requestedNode !== $caller->name) {
            return null;
        }

        $authorizer = app(AgentToolAuthorizer::class);
        $result = $authorizer->authorizeAgentSelfAction($caller, $tool, 'update');

        if (! $result['authorized']) {
            return $this->toolTargetAuthorizationFailed($result['reason'] ?? 'Agent self is not authorized to perform this action.');
        }

        $version = $this->requestString($request, 'version');
        $instance = $this->requestString($request, 'instance');

        return $this->executeUpdate($request, $tool, app(ToolUpdater::class), app(ProgressEventStreamResponseFactory::class), $caller, $caller->name, null, $version, $instance);
    }

    private function executeUpdate(
        Request $request,
        string $tool,
        ToolUpdater $updater,
        ProgressEventStreamResponseFactory $streams,
        Node $caller,
        ?string $node,
        ?string $app,
        ?string $version,
        ?string $instance,
    ): JsonResponse|StreamedResponse {

        $operation = fn (): array|ToolRegistryFailure => $updater->update(
            tool: $tool,
            node: $node,
            app: $app,
            expectedVersion: $version,
            instance: $instance,
        );

        if ($this->wantsEventStream($request)) {
            return $this->streamToolAction(
                streams: $streams,
                title: 'Updating Tool',
                doneFooter: 'Tool updated',
                failFooter: 'Tool update failed',
                operation: $operation,
                data: fn (array $result): array => ['tool' => $result],
                exitCode: fn (): int => 0,
            );
        }

        $result = $operation();

        if ($result instanceof ToolRegistryFailure) {
            return $this->failureResponse($result);
        }

        $this->activitySubject = $caller;

        return response()->json([
            'success' => [
                'data' => [
                    'tool' => $result,
                ],
                'meta' => (object) [],
            ],
        ]);
    }

    private function requestString(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function failureResponse(ToolRegistryFailure $failure): JsonResponse
    {
        $status = match ($failure->code) {
            'tool.not_found' => 404,
            'authorization_failed' => 403,
            'validation_failed' => 422,
            default => 400,
        };

        return response()->json([
            'error' => [
                'code' => $failure->code,
                'message' => $failure->message,
                'meta' => $failure->meta === [] ? (object) [] : $failure->meta,
            ],
        ], $status);
    }

    private function authorizationFailed(string $message): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'authorization_failed',
                'message' => $message,
                'meta' => [],
            ],
        ], 403);
    }

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Write;
    }

    public function type(): string
    {
        return 'api:POST /tools/{tool}/update';
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
        return [];
    }

    public function description(): ?string
    {
        return null;
    }
}
