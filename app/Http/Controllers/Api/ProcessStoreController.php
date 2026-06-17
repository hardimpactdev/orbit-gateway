<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Processes\AddProcess;
use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Enums\ProcessCrashNotification;
use App\Enums\Processes\ProcessRuntime;
use App\Enums\ProcessRestartPolicy;
use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Http\Gateway\GatewayApiException;
use App\Models\Node;
use App\Services\Nodes\Access\NodeAccessAuthorizer;
use App\Services\Processes\ProcessOwnerContextResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[RequiresPermission('process:add', servingNode: ServingNode::AppOwning)]
final class ProcessStoreController implements Loggable
{
    private ?Model $activitySubject = null;

    public function __construct(
        private readonly ProcessOwnerContextResolver $contexts,
        private readonly NodeAccessAuthorizer $authorizer,
    ) {}

    public function __invoke(Request $request, AddProcess $addProcess): JsonResponse
    {
        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return $this->error('authorization_failed', 'Peer identity unknown.', [], 403);
        }

        $input = $this->validatedInput($request);

        if ($input instanceof JsonResponse) {
            return $input;
        }

        try {
            $context = $this->contexts->resolve(
                nodeName: $input['node'],
                appName: $input['app'],
                workspaceName: $input['workspace'],
            );
        } catch (GatewayApiException $e) {
            return $this->error($e->errorCode() ?? 'validation_failed', $e->getMessage(), $e->errorMeta(), 422);
        }

        $authorization = $this->authorizeProcessAccess($caller, $context->node, 'process:add');

        if ($authorization instanceof JsonResponse) {
            return $authorization;
        }

        try {
            $result = $addProcess->handle(
                context: $context,
                name: $input['name'],
                command: $input['command'],
                restartPolicy: $input['restart_policy'],
                crashNotification: $input['crash_notification'],
                start: $input['start'],
                runtime: $input['runtime'],
                tool: $input['tool'],
                definition: $input['definition'],
                version: $input['version'],
            );
        } catch (GatewayApiException $e) {
            return $this->error($e->errorCode() ?? 'validation_failed', $e->getMessage(), $e->errorMeta(), $e->errorCode() === 'process.name_collision' ? 409 : 422);
        }

        $this->activitySubject = $context->subject();

        return response()->json([
            'success' => [
                'data' => $result['data'],
                'meta' => [
                    'warnings' => $result['warnings'],
                ],
            ],
        ]);
    }

    /**
     * @return array{node: string|null, app: string|null, workspace: string|null, name: string, command: string|null, restart_policy: ProcessRestartPolicy, crash_notification: ProcessCrashNotification, runtime: ?ProcessRuntime, tool: string|null, definition: string|null, version: string|null, start: bool}|JsonResponse
     */
    private function validatedInput(Request $request): array|JsonResponse
    {
        $node = $this->optionalString($request, 'node');
        $app = $this->optionalString($request, 'app');
        $workspace = $this->optionalString($request, 'workspace');
        $name = $this->optionalString($request, 'name');
        $command = $this->optionalString($request, 'command');
        $restartPolicyInput = $this->optionalString($request, 'restart_policy') ?? ProcessRestartPolicy::Never->value;
        $crashNotificationInput = $this->optionalString($request, 'crash_notification') ?? ProcessCrashNotification::None->value;
        $runtimeInput = $this->optionalString($request, 'runtime');
        $tool = $this->optionalString($request, 'tool');
        $definition = $this->optionalString($request, 'definition');
        $version = $this->optionalString($request, 'version');

        if ($node !== null && ($app !== null || $workspace !== null)) {
            return $this->error('validation_failed', 'A node context cannot be combined with app or workspace context.', [
                'field' => 'context',
                'node' => $node,
                'app' => $app,
                'workspace' => $workspace,
            ], 422);
        }

        if ($node === null && $app === null && $workspace === null) {
            return $this->error('validation_failed', 'A node, app, or workspace context is required.', ['field' => 'app'], 422);
        }

        if ($name === null) {
            return $this->error('validation_failed', 'The process name is required.', ['field' => 'name'], 422);
        }

        if (! preg_match('/^[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?$/', $name)) {
            return $this->error('validation_failed', 'The process name must contain only lowercase letters, digits, and hyphens, cannot start or end with a hyphen, and may not exceed 64 characters.', ['field' => 'name', 'value' => $name], 422);
        }

        if ($command === null && $definition === null) {
            return $this->error('validation_failed', 'The process command is required.', ['field' => 'command'], 422);
        }

        if ($definition !== null && ! preg_match('/^[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?$/', $definition)) {
            return $this->error('validation_failed', 'The process definition must contain only lowercase letters, digits, and hyphens, cannot start or end with a hyphen, and may not exceed 64 characters.', ['field' => 'definition', 'value' => $definition], 422);
        }

        if ($definition === null && $version !== null) {
            return $this->error('validation_failed', 'Process definition version requires a service process definition.', [
                'field' => 'version',
                'value' => $version,
                'reason' => 'process_definition_version_requires_definition',
            ], 422);
        }

        if ($definition !== null && $node === null) {
            return $this->error('validation_failed', 'Process definitions are only valid for node-owned service processes.', [
                'field' => 'definition',
                'value' => $definition,
                'reason' => 'process_definition_requires_node_owned_process',
            ], 422);
        }

        if ($definition !== null && $tool !== null) {
            return $this->error('validation_failed', 'Service process definitions do not use tool dependencies.', [
                'field' => 'tool',
                'value' => $tool,
                'reason' => 'process_definition_cannot_reference_tool',
            ], 422);
        }

        if ($tool !== null && ! preg_match('/^[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?$/', $tool)) {
            return $this->error('validation_failed', 'The process tool must contain only lowercase letters, digits, and hyphens, cannot start or end with a hyphen, and may not exceed 64 characters.', ['field' => 'tool', 'value' => $tool], 422);
        }

        $restartPolicy = ProcessRestartPolicy::tryFrom($restartPolicyInput);

        if (! $restartPolicy instanceof ProcessRestartPolicy) {
            return $this->error('validation_failed', 'Invalid restart policy.', [
                'field' => 'restart_policy',
                'value' => $restartPolicyInput,
                'allowed' => array_column(ProcessRestartPolicy::cases(), 'value'),
            ], 422);
        }

        $crashNotification = ProcessCrashNotification::tryFrom($crashNotificationInput);

        if (! $crashNotification instanceof ProcessCrashNotification) {
            return $this->error('validation_failed', 'Invalid crash notification policy.', [
                'field' => 'crash_notification',
                'value' => $crashNotificationInput,
                'allowed' => array_column(ProcessCrashNotification::cases(), 'value'),
            ], 422);
        }

        $runtime = null;

        if ($runtimeInput !== null) {
            $runtime = ProcessRuntime::tryFrom($runtimeInput);

            if (! $runtime instanceof ProcessRuntime) {
                return $this->error('validation_failed', 'Invalid process runtime.', [
                    'field' => 'runtime',
                    'value' => $runtimeInput,
                    'allowed' => array_column(ProcessRuntime::cases(), 'value'),
                ], 422);
            }

            if ($node === null && $definition === null && $runtime->appWorkspaceCommandViolationReason() !== null) {
                return $this->error('validation_failed', $runtime->appWorkspaceCommandViolationMessage() ?? 'The selected runtime is not valid for this process owner.', [
                    'field' => 'runtime',
                    'value' => $runtimeInput,
                    'reason' => $runtime->appWorkspaceCommandViolationReason(),
                ], 422);
            }
        }

        return [
            'node' => $node,
            'app' => $app,
            'workspace' => $workspace,
            'name' => $name,
            'command' => $command,
            'restart_policy' => $restartPolicy,
            'crash_notification' => $crashNotification,
            'runtime' => $runtime,
            'tool' => $tool,
            'definition' => $definition,
            'version' => $version,
            'start' => $request->boolean('start'),
        ];
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
                'meta' => empty($meta) ? (object) [] : $meta,
            ],
        ], $status);
    }

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Write;
    }

    public function type(): string
    {
        return 'api:POST /processes';
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
            'tool' => $this->optionalString(request(), 'tool'),
            'definition' => $this->optionalString(request(), 'definition'),
        ];
    }

    public function description(): ?string
    {
        return null;
    }
}
