<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Http\Controllers\Api\Concerns\ResolvesVisibleToolNodes;
use App\Http\Controllers\Api\Concerns\StreamsToolActionProgress;
use App\Models\Node;
use App\Services\Tools\ToolRegistryFailure;
use App\Services\Tools\ToolRemover;
use App\Support\Streaming\ProgressEventStreamResponseFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ToolRemoveController implements Loggable
{
    use ResolvesVisibleToolNodes;
    use StreamsToolActionProgress;

    private ?Node $activitySubject = null;

    private ?string $activityTool = null;

    private ?string $activityNode = null;

    private ?string $activityApp = null;

    private ?string $activityConsentSource = null;

    public function __invoke(
        Request $request,
        string $tool,
        ToolRemover $remover,
        ProgressEventStreamResponseFactory $streams,
    ): JsonResponse|StreamedResponse {
        $this->activityTool = $tool;

        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return $this->authorizationFailed('Peer identity unknown.');
        }

        $visibleNodeIds = $this->visibleToolNodeIds($caller, false, 'tool:remove');

        if (! $this->nodeRoleAssignments()->nodeIsGateway($caller) && $visibleNodeIds === []) {
            return $this->authorizationFailed('This node is not authorized to manage tools.');
        }

        if ($request->boolean('destructive_consent') !== true) {
            return $this->errorResponse(
                code: 'validation_failed',
                message: 'Use --force or --json to remove this tool.',
                meta: ['field' => 'force', 'reason' => 'destructive_consent_required'],
                status: 422,
            );
        }

        $this->activityConsentSource = $this->destructiveConsentSource($request);

        if ($this->toolTargetString($request, 'node') === null && $this->toolTargetString($request, 'app') === null) {
            return $this->errorResponse(
                code: 'validation_failed',
                message: 'A node or app target is required. Provide --node or --app.',
                meta: ['fields' => ['target']],
                status: 422,
            );
        }

        $target = $this->authorizedToolTarget($request, $caller, $visibleNodeIds);

        if ($target instanceof JsonResponse) {
            return $target;
        }

        $node = $target['node'];
        $app = $target['app'];

        $agentSelfAuth = $this->authorizeAgentToolAction($caller, $node, $tool, 'remove');

        if ($agentSelfAuth instanceof JsonResponse) {
            return $agentSelfAuth;
        }

        $this->activityNode = $node;
        $this->activityApp = $app;
        $operation = fn (): array|ToolRegistryFailure => $remover->remove($tool, node: $node, app: $app);

        if ($this->wantsEventStream($request)) {
            return $this->streamToolAction(
                streams: $streams,
                title: 'Removing Tool',
                doneFooter: 'Tool removed',
                failFooter: 'Tool remove failed',
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

    private function failureResponse(ToolRegistryFailure $failure): JsonResponse
    {
        $status = match ($failure->code) {
            'tool.not_found' => 404,
            'authorization_failed' => 403,
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

    /**
     * @param  array<string, mixed>  $meta
     */
    private function errorResponse(string $code, string $message, array $meta, int $status): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'meta' => empty($meta) ? (object) [] : $meta,
            ],
        ], $status);
    }

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Destructive;
    }

    public function type(): string
    {
        return 'api:DELETE /tools/{tool}';
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
        return array_filter([
            'tool' => $this->activityTool,
            'node' => $this->activityNode,
            'app' => $this->activityApp,
            'destructive_consent' => $this->activityConsentSource !== null ? true : null,
            'destructive_consent_source' => $this->activityConsentSource,
        ], static fn (mixed $value): bool => $value !== null);
    }

    private function destructiveConsentSource(Request $request): string
    {
        $source = $request->input('destructive_consent_source');

        return is_string($source) && trim($source) !== '' ? trim($source) : 'unspecified';
    }

    public function description(): ?string
    {
        return null;
    }
}
