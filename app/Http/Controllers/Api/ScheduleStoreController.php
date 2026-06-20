<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Schedules\AddSchedule;
use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Http\Controllers\Api\Concerns\LogsScheduleApiActivity;
use App\Http\Gateway\GatewayApiException;
use App\Models\App;
use App\Models\Node;
use App\Models\Schedule;
use App\Services\Nodes\Access\NodeAccessAuthorizer;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class ScheduleStoreController implements Loggable
{
    use LogsScheduleApiActivity;

    public function __construct(
        private NodeAccessAuthorizer $authorizer,
    ) {}

    public function __invoke(Request $request, AddSchedule $addSchedule): JsonResponse
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

        $target = $this->resolveTarget($input['app'], $input['node']);

        if ($target instanceof JsonResponse) {
            return $target;
        }

        $authorization = $this->authorizeTarget($caller, $target);

        if ($authorization instanceof JsonResponse) {
            return $authorization;
        }

        try {
            $result = $addSchedule->handle(
                target: $target,
                name: $input['name'],
                interval: $input['interval'],
                timezone: $input['timezone'],
                executionType: $input['execution_type'],
                executionValue: $input['execution_value'],
            );
            $schedule = Schedule::query()->where('name', $input['name'])->first();

            if ($schedule instanceof Schedule) {
                $this->setScheduleActivitySubject($request, $schedule);
            }
        } catch (GatewayApiException $e) {
            return $this->error(
                code: $e->errorCode() ?? 'validation_failed',
                message: $e->getMessage(),
                meta: $e->errorMeta(),
                status: $this->status($e),
                data: $e->errorData(),
            );
        }

        return response()->json(['success' => $result], 201);
    }

    /**
     * @return array{name: string, app: string|null, node: string|null, interval: string, timezone: string, execution_type: string, execution_value: string}|JsonResponse
     */
    private function validatedInput(Request $request): array|JsonResponse
    {
        $name = $this->optionalString($request, 'name');
        $app = $this->optionalString($request, 'app');
        $node = $this->optionalString($request, 'node');
        $interval = $this->optionalString($request, 'interval');
        $timezone = $this->optionalString($request, 'timezone') ?? 'UTC';
        $command = $this->optionalString($request, 'command');
        $script = $this->optionalString($request, 'script');

        if ($name === null) {
            return $this->error('validation_failed', 'The schedule name is required.', ['field' => 'name'], 422);
        }

        if (! preg_match('/^[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?$/', $name)) {
            return $this->error('validation_failed', 'The schedule name must contain only lowercase letters, digits, and hyphens, cannot start or end with a hyphen, and may not exceed 64 characters.', ['field' => 'name', 'value' => $name], 422);
        }

        if (($app === null) === ($node === null)) {
            return $this->error('validation_failed', 'Exactly one schedule target is required.', ['fields' => ['app', 'node']], 422);
        }

        if (($command === null) === ($script === null)) {
            return $this->error('validation_failed', 'Exactly one schedule execution source is required.', ['fields' => ['command', 'script']], 422);
        }

        if ($interval === null) {
            return $this->error('schedule.interval_invalid', 'The schedule interval is required.', ['field' => 'interval'], 422);
        }

        if (! in_array($timezone, timezone_identifiers_list(), true)) {
            return $this->error('validation_failed', 'The schedule timezone must be a valid IANA timezone.', ['field' => 'timezone', 'value' => $timezone], 422);
        }

        return [
            'name' => $name,
            'app' => $app,
            'node' => $node,
            'interval' => $interval,
            'timezone' => $timezone,
            'execution_type' => $command === null ? 'script' : 'command',
            'execution_value' => $command ?? (string) $script,
        ];
    }

    private function resolveTarget(?string $app, ?string $node): App|Node|JsonResponse
    {
        if ($app !== null) {
            $target = App::query()->with('node.schedulerState')->where('name', $app)->first();

            return $target instanceof App
                ? $target
                : $this->error('validation_failed', "App '{$app}' not found.", ['field' => 'app', 'value' => $app], 422);
        }

        $target = Node::query()
            ->with('schedulerState')
            ->where('name', $node)
            ->whereIn('id', app(NodeRoleAssignments::class)->activeGatewayOrAppHostNodeIds())
            ->first();

        return $target instanceof Node
            ? $target
            : $this->error('validation_failed', "Node '{$node}' not found.", ['field' => 'node', 'value' => $node], 422);
    }

    private function authorizeTarget(Node $caller, App|Node $target): ?JsonResponse
    {
        $servingNode = $target instanceof App ? $target->node : $target;

        if (! $servingNode instanceof Node) {
            return $this->error('authorization_failed', 'This node is not authorized to manage schedules for the selected scope.', [
                'reason' => 'serving_node_unresolved',
                'missing_permission' => 'schedule:add',
            ], 403);
        }

        $result = $this->authorizer->authorize($caller, $servingNode, 'schedule:add');

        if ($result->allowed) {
            return null;
        }

        return $this->error('authorization_failed', 'This node is not authorized to manage schedules for the selected scope.', [
            'reason' => $result->reason,
            'missing_permission' => $result->missingPermission,
            'serving_node' => $servingNode->name,
        ], 403);
    }

    private function optionalString(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $data
     */
    private function error(string $code, string $message, array $meta, int $status, array $data = []): JsonResponse
    {
        $error = [
            'code' => $code,
            'message' => $message,
            'meta' => empty($meta) ? (object) [] : $meta,
        ];

        if ($data !== []) {
            $error['data'] = $data;
        }

        return response()->json(['error' => $error], $status);
    }

    private function status(GatewayApiException $e): int
    {
        return $e->errorCode() === 'schedule.name_collision' ? 409 : 422;
    }

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Write;
    }

    public function type(): string
    {
        return 'api:POST /schedules';
    }
}
