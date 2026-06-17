<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Enums\Nodes\NodeStatus;
use App\Models\Node;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\S3\S3UnpublishAction;
use App\Support\Streaming\ProgressEventStreamEmitter;
use App\Support\Streaming\ProgressEventStreamResponseFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class S3UnpublishController implements Loggable
{
    private ?Node $activitySubject = null;

    private string $activityHost = '';

    private string $activityNode = '';

    public function __invoke(
        Request $request,
        string $host,
        S3UnpublishAction $unpublishAction,
        NodeRoleAssignments $nodeRoleAssignments,
        ProgressEventStreamResponseFactory $streams,
    ): JsonResponse|StreamedResponse {
        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return $this->authorizationFailed('Peer identity unknown.');
        }

        $input = $this->validatedInput($request, $nodeRoleAssignments, $caller, $host);

        if ($input instanceof JsonResponse) {
            return $input;
        }

        $this->activitySubject = $caller;
        $this->activityHost = $input['host'];
        $this->activityNode = $input['node'];

        return $streams->make(function (ProgressEventStreamEmitter $emitter) use ($unpublishAction, $caller, $input): void {
            $emitter->tree('Unpublishing S3 Host', [
                ['key' => 'confirm_destructive', 'label' => 'Confirm destructive removal'],
                ['key' => 'resolve_node', 'label' => 'Resolve S3 node'],
                ['key' => 'check_router', 'label' => 'Check router'],
                ['key' => 'remove_ingress', 'label' => 'Remove ingress host'],
                ['key' => 'remove_seaweedfs_config', 'label' => 'Remove SeaweedFS public host config'],
                ['key' => 'apply_cleanup', 'label' => 'Apply route cleanup'],
            ]);

            $result = $unpublishAction->unpublishWithProgress($caller, $input['node'], $input['host'], $emitter);

            if (isset($result['error'])) {
                $error = $result['error'];
                $code = is_string($error['code'] ?? null) ? $error['code'] : 's3.unpublish_failed';
                $message = is_string($error['message'] ?? null) ? $error['message'] : 'S3 unpublish failed.';
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
        string $host,
    ): array|JsonResponse {
        $host = trim($host);

        if ($host === '') {
            return $this->validationFailed(
                'host',
                'A public hostname is required.',
                ['field' => 'host'],
            );
        }

        $node = $this->requestString($request, 'node');

        if ($node === null) {
            $node = $this->resolveDefaultS3Node($nodeRoleAssignments, $caller);

            if ($node === null) {
                return $this->validationFailed(
                    'node',
                    'An active s3 role node is required to unpublish an S3 host.',
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
        return ActivityLogType::Destructive;
    }

    public function type(): string
    {
        return 'api:DELETE /s3/public-hosts/{host}';
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
