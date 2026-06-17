<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Enums\Nodes\NodeStatus;
use App\Http\Controllers\Api\Concerns\ResolvesVisibleToolNodes;
use App\Http\Controllers\Api\Concerns\StreamsToolActionProgress;
use App\Models\Node;
use App\Services\Tools\AgentToolAuthorizer;
use App\Services\Tools\ToolCatalog;
use App\Services\Tools\ToolInstaller;
use App\Services\Tools\ToolRegistryFailure;
use App\Support\Streaming\ProgressEventStreamResponseFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ToolInstallController implements Loggable
{
    use ResolvesVisibleToolNodes;
    use StreamsToolActionProgress;

    private ?Node $activitySubject = null;

    public function __invoke(
        Request $request,
        string $tool,
        ToolCatalog $catalog,
        ToolInstaller $installer,
        ProgressEventStreamResponseFactory $streams,
    ): JsonResponse|StreamedResponse {
        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return $this->authorizationFailed('Peer identity unknown.');
        }

        $allowAnyActiveNode = $catalog->requiredNodeRole($tool) !== null;
        $visibleNodeIds = $this->visibleToolNodeIds($caller, $allowAnyActiveNode, 'tool:install');

        if (! $this->nodeRoleAssignments()->nodeIsGateway($caller) && $visibleNodeIds === []) {
            return $this->authorizationFailed('This node is not authorized to manage tools.');
        }

        $inputFailure = $this->validateInstallInput($request);

        if ($inputFailure instanceof JsonResponse) {
            return $inputFailure;
        }

        $target = $this->authorizedToolTarget(
            $request,
            $caller,
            $visibleNodeIds,
            allowAnyActiveNode: $allowAnyActiveNode,
        );

        if ($target instanceof JsonResponse) {
            return $target;
        }

        $node = $target['node'];
        $app = $target['app'];

        $agentSelfAuth = $this->authorizeAgentToolAction($caller, $node, $tool, 'install');

        if ($agentSelfAuth instanceof JsonResponse) {
            return $agentSelfAuth;
        }

        $status = (string) $request->input('status', 'installed');
        $toolConfig = $request->input('config', []);

        if (! is_array($toolConfig)) {
            $toolConfig = [];
        }

        $operation = fn (): array|ToolRegistryFailure => $installer->install(
            tool: $tool,
            node: $node,
            app: $app,
            expectedState: $status,
            config: $toolConfig,
            version: $this->requestTargetString($request, 'version'),
            runtime: $this->requestTargetString($request, 'runtime'),
            instance: $this->requestTargetString($request, 'instance'),
            withProcess: $request->boolean('with_process', true),
        );

        $meta = (object) [];

        if ($node !== null) {
            $targetNode = Node::query()->where('name', $node)->where('status', NodeStatus::Active->value)->first();

            if ($targetNode instanceof Node) {
                $warning = app(AgentToolAuthorizer::class)->multipleAgentToolsWarning($targetNode, $tool);

                if ($warning !== null) {
                    $meta = [
                        'warnings' => [$warning],
                    ];
                }
            }
        }

        if ($this->wantsEventStream($request)) {
            return $this->streamToolAction(
                streams: $streams,
                title: 'Installing Tool',
                doneFooter: 'Tool installed',
                failFooter: 'Tool install failed',
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
                'meta' => $meta,
            ],
        ]);
    }

    private function validateInstallInput(Request $request): ?JsonResponse
    {
        $status = $request->input('status', 'installed');

        if (! is_string($status) || $status !== 'installed') {
            $statusValue = is_scalar($status) ? (string) $status : get_debug_type($status);

            return response()->json([
                'error' => [
                    'code' => 'validation_failed',
                    'message' => "Invalid status value '{$statusValue}'. Valid values: installed.",
                    'meta' => [
                        'field' => 'status',
                        'value' => $statusValue,
                        'reason' => 'unsupported_value',
                    ],
                ],
            ], 422);
        }

        foreach (['expected_version', 'expected-version'] as $field) {
            if ($request->exists($field)) {
                return response()->json([
                    'error' => [
                        'code' => 'validation_failed',
                        'message' => 'Update-only version intent is not supported during install. Use tool:update --expected-version after install.',
                        'meta' => [
                            'field' => $field,
                            'reason' => 'unsupported_field',
                        ],
                    ],
                ], 422);
            }
        }

        if ($this->requestTargetString($request, 'node') === null && $this->requestTargetString($request, 'app') === null) {
            return response()->json([
                'error' => [
                    'code' => 'validation_failed',
                    'message' => 'A node or app target is required.',
                    'meta' => [
                        'fields' => ['target'],
                    ],
                ],
            ], 422);
        }

        return null;
    }

    private function requestTargetString(Request $request, string $key): ?string
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
        return 'api:POST /tools/{tool}/install';
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
