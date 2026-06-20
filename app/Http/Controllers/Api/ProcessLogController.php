<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Processes\ShowProcessLogs;
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
use Symfony\Component\HttpFoundation\StreamedResponse;

#[RequiresPermission('process:logs', servingNode: ServingNode::AppOwning)]
final class ProcessLogController implements Loggable
{
    private ?Model $activitySubject = null;

    public function __construct(
        private readonly NodeAccessAuthorizer $authorizer,
        private readonly ProcessOwnerContextResolver $contexts,
    ) {}

    public function __invoke(string $name, Request $request, ShowProcessLogs $showProcessLogs): JsonResponse|StreamedResponse
    {
        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return $this->error('authorization_failed', 'Peer identity unknown.', [], 403);
        }

        try {
            $context = $this->contexts->resolve(
                nodeName: $this->optionalString($request, 'node'),
                appName: $this->optionalString($request, 'app'),
                workspaceName: $this->optionalString($request, 'workspace'),
            );
        } catch (GatewayApiException $e) {
            return $this->error($e->errorCode() ?? 'validation_failed', $e->getMessage(), $e->errorMeta(), $this->statusFor($e));
        }

        $authorization = $this->authorizeProcessAccess($caller, $context->node, 'process:logs');

        if ($authorization instanceof JsonResponse) {
            return $authorization;
        }

        if ($request->boolean('follow')) {
            try {
                $target = $showProcessLogs->streamTarget($context, $name, $this->lines($request));
            } catch (GatewayApiException $e) {
                return $this->error($e->errorCode() ?? 'validation_failed', $e->getMessage(), $e->errorMeta(), $this->statusFor($e));
            }

            $this->activitySubject = $context->subject();

            return response()->stream(function () use ($showProcessLogs, $target): void {
                $showProcessLogs->followTarget($target, function (string $output): void {
                    echo $output;

                    if (PHP_SAPI === 'fpm-fcgi' || PHP_SAPI === 'cli-server') {
                        @ob_flush();
                        @flush();
                    }
                });
            }, 200, [
                'Cache-Control' => 'no-cache',
                'Content-Type' => 'text/plain; charset=UTF-8',
                'X-Accel-Buffering' => 'no',
            ]);
        }

        try {
            $result = $showProcessLogs->handle($context, $name, $this->lines($request));
        } catch (GatewayApiException $e) {
            return $this->error($e->errorCode() ?? 'validation_failed', $e->getMessage(), $e->errorMeta(), $this->statusFor($e));
        }

        $this->activitySubject = $context->subject();

        return response()->json([
            'success' => [
                'data' => $result['data'],
                'meta' => $result['meta'],
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
        ], 403);
    }

    private function lines(Request $request): int
    {
        $value = $request->input('lines', 100);

        return is_numeric($value) ? (int) $value : 0;
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
            'process.log_read_failed' => 502,
            default => 422,
        };
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
                'meta' => empty($meta) ? (object) [] : $meta,
            ],
        ], $status);
    }

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Read;
    }

    public function type(): string
    {
        return 'api:GET /processes/{name}/log';
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
        ];
    }

    public function description(): ?string
    {
        return null;
    }
}
