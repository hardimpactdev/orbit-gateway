<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Models\Node;
use App\Models\OperationRun;
use App\Services\Nodes\GatewayNodeCreator;
use App\Services\Operations\OperationRunRecorder;
use App\Support\Streaming\ProgressEventStreamEmitter;
use App\Support\Streaming\ProgressEventStreamResponseFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

#[RequiresPermission('node:new', servingNode: ServingNode::Gateway)]
final readonly class NodeStoreController implements Loggable
{
    public function __invoke(
        Request $request,
        ProgressEventStreamResponseFactory $streams,
        OperationRunRecorder $operationRuns,
        GatewayNodeCreator $nodes,
    ): JsonResponse|StreamedResponse {
        /** @var mixed $resolvedUser */
        $resolvedUser = $request->user();
        $caller = $resolvedUser instanceof Node ? $resolvedUser : null;

        if (! $caller instanceof Node) {
            return $this->forbidden();
        }

        $arguments = $this->nodeNewArguments($request);

        if ($this->wantsEventStream($request)) {
            return $this->stream($request, $streams, $operationRuns, $nodes, $caller, $arguments);
        }

        $result = $nodes->create($arguments);

        return response()->json($result->payload, $result->successful() ? 200 : 422);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function stream(
        Request $request,
        ProgressEventStreamResponseFactory $streams,
        OperationRunRecorder $operationRuns,
        GatewayNodeCreator $nodes,
        Node $caller,
        array $arguments,
    ): StreamedResponse {
        $operationRun = $operationRuns->queued(
            operationId: (string) Str::uuid(),
            lane: 'gateway',
            internalCommand: 'node:new',
            operationType: 'node:new',
            callerNodeId: $caller->id,
        );

        return $streams->make(function (ProgressEventStreamEmitter $events) use ($operationRuns, $operationRun, $nodes, $arguments, $request): void {
            $events->tree('Creating Node', [
                ['key' => 'operation', 'label' => 'Record operation state'],
                ['key' => 'node', 'label' => 'Run node creation'],
            ]);

            $operationRun = $operationRuns->running($operationRun->id);
            $events->stepEvent('operation', 'done', "Operation {$operationRun->id} running");
            $events->stepEvent('node', 'running', 'Running node:new');

            try {
                $result = $nodes->create($arguments);
                $payload = $result->payload;

                if ($result->successful()) {
                    $operationRun = $operationRuns->succeeded($operationRun->id, 0, $payload);
                    $events->stepEvent('node', 'done', 'Node created');
                    $events->complete(0, [
                        'footer' => $this->nodeCreatedFooter($payload, $request),
                        'operation_run' => $this->operationRunPayload($operationRun),
                        'result' => $payload,
                    ]);

                    return;
                }

                $error = $this->errorFramePayload($payload, 'node.creation_failed', 'Node creation failed.');
                $operationRun = $operationRuns->failed($operationRun->id, $result->exitCode, $error);
                $events->stepEvent('node', 'fail', $error['message']);
                $events->error($error['message'], 1, [
                    ...$error,
                    'operation_run' => $this->operationRunPayload($operationRun),
                ]);

                return;
            } catch (Throwable $exception) {
                $error = [
                    'code' => 'node.creation_failed',
                    'message' => $exception->getMessage() !== '' ? $exception->getMessage() : 'Node creation failed.',
                    'meta' => [],
                ];
                $operationRun = $operationRuns->failed($operationRun->id, 1, $error);
                $events->stepEvent('node', 'fail', $error['message']);
                $events->error($error['message'], 1, [
                    ...$error,
                    'operation_run' => $this->operationRunPayload($operationRun),
                ]);
            }
        });
    }

    private function wantsEventStream(Request $request): bool
    {
        return in_array('text/event-stream', $request->getAcceptableContentTypes(), true);
    }

    /**
     * @return array<string, mixed>
     */
    private function nodeNewArguments(Request $request): array
    {
        $arguments = [
            'name' => $this->optionalString($request, 'name'),
            '--json' => true,
        ];

        $this->addTemplateOption($arguments, $request);
        $this->addRolesOption($arguments, $request);
        $this->addOperatorOption($arguments, $request);
        $this->addStringOption($arguments, '--host', $request, 'host');
        $this->addStringOption($arguments, '--ingress', $request, 'ingress_node');
        $this->addStringOption($arguments, '--tld', $request, 'tld');
        $this->addStringOption($arguments, '--operator-name', $request, 'operator_name');
        $this->addStringOption($arguments, '--redis-node', $request, 'redis_node');
        $this->addStringOption($arguments, '--postgres-node', $request, 'postgres_node');
        $this->addStringOption($arguments, '--clickhouse-node', $request, 'clickhouse_node');
        $this->addStringOption($arguments, '--s3-data-path', $request, 's3_data_path');
        $this->addStringOption($arguments, '--user', $request, 'user');
        $this->addStringOption($arguments, '--gateway-endpoint', $request, 'gateway_endpoint');
        $this->addStringOption($arguments, '--host-key-fingerprint', $request, 'host_key_fingerprint');
        $this->addStringOption($arguments, '--self-grant', $request, 'self_grant');
        $this->addStringOption($arguments, '--self-grant-permissions', $request, 'self_grant_permissions');
        $this->addArrayOption($arguments, '--grant-to', $request, 'grant_to');
        $this->addStringOption($arguments, '--grant-to-preset', $request, 'grant_to_preset');
        $this->addStringOption($arguments, '--grant-to-permissions', $request, 'grant_to_permissions');
        $this->addArrayOption($arguments, '--grant-from', $request, 'grant_from');
        $this->addStringOption($arguments, '--grant-from-preset', $request, 'grant_from_preset');
        $this->addStringOption($arguments, '--grant-from-permissions', $request, 'grant_from_permissions');
        $this->addArrayOption($arguments, '--agent-tool', $request, 'agent_tools');

        return $arguments;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{code: string, message: string, meta: array<string, mixed>, data?: array<string, mixed>}
     */
    private function errorFramePayload(array $payload, string $fallbackCode, string $fallbackMessage): array
    {
        $error = $payload['error'] ?? [];
        $error = is_array($error) ? $error : [];

        return array_filter([
            'code' => is_string($error['code'] ?? null) ? $error['code'] : $fallbackCode,
            'message' => is_string($error['message'] ?? null) ? $error['message'] : $fallbackMessage,
            'meta' => is_array($error['meta'] ?? null) ? $error['meta'] : [],
            'data' => is_array($error['data'] ?? null) ? $error['data'] : null,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    private function operationRunPayload(OperationRun $operationRun): array
    {
        return [
            'id' => $operationRun->id,
            'operation_id' => $operationRun->operation_id,
            'type' => $operationRun->operation_type,
            'status' => $operationRun->status->value,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function nodeCreatedFooter(array $payload, Request $request): string
    {
        $node = $payload['success']['data']['node'] ?? null;
        $name = is_array($node) && is_string($node['name'] ?? null)
            ? $node['name']
            : $this->optionalString($request, 'name');

        return $name !== null ? "Node '{$name}' created." : 'Node created.';
    }

    private function forbidden(): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'authorization_failed',
                'message' => 'This caller cannot create nodes.',
                'meta' => [],
            ],
        ], 403);
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

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function addArrayOption(array &$arguments, string $option, Request $request, string $key): void
    {
        $value = $request->input($key);

        if (! is_array($value) || $value === []) {
            return;
        }

        $filtered = array_values(array_filter($value, fn ($item): bool => is_string($item) && $item !== ''));

        if ($filtered !== []) {
            $arguments[$option] = $filtered;
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function addTemplateOption(array &$arguments, Request $request): void
    {
        $this->addStringOption($arguments, '--template', $request, 'template');
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function addRolesOption(array &$arguments, Request $request): void
    {
        $roles = $request->input('roles');

        if (is_array($roles)) {
            $resolvedRoles = array_values(array_filter($roles, fn ($role): bool => is_string($role) && $role !== ''));

            if ($resolvedRoles !== []) {
                $arguments['--roles'] = implode(',', $resolvedRoles);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function addOperatorOption(array &$arguments, Request $request): void
    {
        if ($request->boolean('operator')) {
            $arguments['--operator'] = true;
        }
    }

    private function optionalString(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        return is_string($value) && $value !== '' ? $value : null;
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
        return 'node.created';
    }

    public function activityLogAction(): string
    {
        return $this->type();
    }

    public function subject(): ?Model
    {
        $name = request('name');

        if (! is_string($name) || $name === '') {
            return null;
        }

        return Node::query()->where('name', $name)->first();
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
            'name' => $this->requestString('name'),
            'template' => $this->requestString('template'),
            'operator' => request()->boolean('operator'),
            'roles' => request('roles'),
            'tld' => $this->requestString('tld') ?? $this->createdNodeTld(),
            'redis_node' => $this->requestString('redis_node'),
            'postgres_node' => $this->requestString('postgres_node'),
            'clickhouse_node' => $this->requestString('clickhouse_node'),
            's3_data_path' => $this->requestString('s3_data_path'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function activityLogProperties(): array
    {
        return $this->properties();
    }

    private function createdNodeTld(): ?string
    {
        $subject = $this->subject();

        if (! $subject instanceof Node) {
            return null;
        }

        return is_string($subject->tld) && $subject->tld !== '' ? $subject->tld : null;
    }

    public function description(): ?string
    {
        $name = $this->requestString('name');

        return $name !== null ? "Created node {$name}." : null;
    }

    public function activityLogDescription(): ?string
    {
        return $this->description();
    }

    private function requestString(string $key): ?string
    {
        $value = request($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
