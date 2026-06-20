<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Enums\Nodes\NodeStatus;
use App\Models\Node;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\S3\S3PublishAction;
use App\Support\Streaming\ProgressEventStreamEmitter;
use App\Support\Streaming\ProgressEventStreamResponseFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class S3PublishController implements Loggable
{
    private ?Node $activitySubject = null;

    private string $activityHost = '';

    private string $activityNode = '';

    public function __invoke(
        Request $request,
        S3PublishAction $publishAction,
        NodeRoleAssignments $nodeRoleAssignments,
        ProgressEventStreamResponseFactory $streams,
    ): JsonResponse|StreamedResponse {
        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return $this->authorizationFailed('Peer identity unknown.');
        }

        $input = $this->validatedInput($request, $nodeRoleAssignments, $caller);

        if ($input instanceof JsonResponse) {
            return $input;
        }

        $this->activitySubject = $caller;
        $this->activityHost = $input['host'];
        $this->activityNode = $input['node'];

        return $streams->make(function (ProgressEventStreamEmitter $emitter) use ($publishAction, $caller, $input): void {
            $emitter->tree('Publishing S3 Host', [
                ['key' => 'resolve_node', 'label' => 'Resolve S3 node'],
                ['key' => 'check_router_ingress', 'label' => 'Check router and ingress'],
                ['key' => 'ensure_credentials', 'label' => 'Ensure SeaweedFS credentials'],
                ['key' => 'ensure_private_route', 'label' => 'Ensure private s3.orbit route'],
                ['key' => 'ensure_backend_pool', 'label' => 'Ensure S3 backend pool'],
                ['key' => 'publish_ingress', 'label' => 'Publish ingress host'],
                ['key' => 'verify_intent', 'label' => 'Verify route intent'],
            ]);

            $result = $publishAction->publishWithProgress($caller, $input['node'], $input['host'], $emitter);

            if (isset($result['error'])) {
                $error = $result['error'];
                $code = is_string($error['code'] ?? null) ? $error['code'] : 's3.publish_failed';
                $message = is_string($error['message'] ?? null) ? $error['message'] : 'S3 publish failed.';
                $meta = is_array($error['meta'] ?? null) ? $error['meta'] : [];

                $emitter->error($message, 1, [
                    'code' => $code,
                    'message' => $message,
                    'meta' => $meta,
                ]);

                return;
            }

            $success = $result['success'] ?? [];
            $s3Data = is_array($success['data'] ?? null) ? $success['data'] : [];
            $meta = is_array($success['meta'] ?? null) ? $success['meta'] : [];

            // Emit the payload so the CLI SSE frame data matches the documented
            // success shape: s3 + meta at the top level of the complete frame payload.
            $emitter->complete(0, [
                's3' => $s3Data['s3'] ?? (object) [],
                'meta' => $meta,
            ]);
        });
    }

    /**
     * @return array{host: string, node: string}|JsonResponse
     */
    private function validatedInput(
        Request $request,
        NodeRoleAssignments $nodeRoleAssignments,
        Node $caller,
    ): array|JsonResponse {
        $host = $this->requestString($request, 'host');
        $node = $this->requestString($request, 'node');

        if ($host === null) {
            return $this->validationFailed(
                'host',
                'A public hostname is required.',
                ['field' => 'host'],
            );
        }

        if ($node === null) {
            // Try to auto-resolve if exactly one active s3 node exists.
            $node = $this->resolveDefaultS3Node($nodeRoleAssignments, $caller);

            if ($node === null) {
                return $this->validationFailed(
                    'node',
                    'An active s3 role node is required to publish an S3 host.',
                    ['field' => 'node', 'required_role' => 's3'],
                );
            }
        }

        return ['host' => $host, 'node' => $node];
    }

    /**
     * Auto-resolve the s3 node name when exactly one active s3 node is visible.
     */
    private function resolveDefaultS3Node(NodeRoleAssignments $nodeRoleAssignments, Node $caller): ?string
    {
        $s3NodeIds = $nodeRoleAssignments->activeNodeIdsForRole('s3');

        if ($s3NodeIds === []) {
            return null;
        }

        if ($nodeRoleAssignments->nodeIsGateway($caller)) {
            $nodes = Node::query()
                ->where('status', NodeStatus::Active->value)
                ->whereIn('id', $s3NodeIds)
                ->limit(2)
                ->get();

            if ($nodes->count() === 1) {
                return $nodes->first()->name;
            }

            return null;
        }

        // For non-gateway callers, apply visibility constraints.
        $visibleS3Nodes = Node::query()
            ->where('status', NodeStatus::Active->value)
            ->whereIn('id', $s3NodeIds)
            ->get();

        $visibleNames = [];

        foreach ($visibleS3Nodes as $node) {
            $visibleNames[] = $node->name;
        }

        if (count($visibleNames) === 1) {
            return $visibleNames[0];
        }

        return null;
    }

    private function requestString(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function validationFailed(string $field, string $message, array $meta = []): JsonResponse
    {
        if ($meta === []) {
            $meta = ['field' => $field];
        }

        return response()->json([
            'error' => [
                'code' => 'validation_failed',
                'message' => $message,
                'meta' => $meta,
            ],
        ], 422);
    }

    private function authorizationFailed(string $message): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'authorization_failed',
                'message' => $message,
                'meta' => (object) [],
            ],
        ], 403);
    }

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Write;
    }

    public function type(): string
    {
        return 'api:POST /s3/public-hosts';
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
            'host' => $this->activityHost,
            'node' => $this->activityNode,
        ];
    }

    public function description(): ?string
    {
        return null;
    }
}
