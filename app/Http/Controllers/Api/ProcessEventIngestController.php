<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Enums\ProcessEventType;
use App\Models\Node;
use App\Models\ProcessEvent;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Processes\ProcessRuntimeUnitResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final readonly class ProcessEventIngestController implements Loggable
{
    public function __construct(
        private ProcessRuntimeUnitResolver $resolver,
        private NodeRoleAssignments $nodeRoleAssignments,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node || ! $caller->isActive() || ! $this->nodeRoleAssignments->nodeHasActiveAppHostRole($caller)) {
            return response()->json([
                'error' => [
                    'code' => 'authorization_failed',
                    'message' => 'Only active app-host identities may ingest process crash events.',
                    'meta' => (object) [],
                ],
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'event_id' => ['required', 'string', 'max:255'],
            'event' => ['required', 'string', Rule::in([ProcessEventType::Crashed->value])],
            'unit' => ['required', 'string', 'max:255'],
            'exit_code' => ['required', 'integer'],
            'exit_status' => ['required', 'string', 'max:64'],
            'at' => ['required', 'string', 'date'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => [
                    'code' => 'validation_failed',
                    'message' => 'The process event payload is invalid.',
                    'meta' => [
                        'errors' => $validator->errors()->toArray(),
                    ],
                ],
            ], 422);
        }

        /** @var array{event_id: string, unit: string, exit_code: int, exit_status: string, at: string} $payload */
        $payload = $validator->validated();

        $existing = ProcessEvent::query()
            ->where('event_id', $payload['event_id'])
            ->first();

        if ($existing instanceof ProcessEvent) {
            return response()->json([
                'success' => [
                    'data' => ['id' => $existing->id],
                    'meta' => ['idempotent' => true],
                ],
            ]);
        }

        $resolved = $this->resolver->resolve($caller, $payload['unit']);

        $event = ProcessEvent::query()->create([
            'event' => ProcessEventType::Crashed,
            'event_id' => $payload['event_id'],
            'process_id' => $resolved['process']->id ?? null,
            'app_id' => $resolved['app']->id ?? null,
            'workspace_id' => $resolved['workspace']->id ?? null,
            'node_id' => $caller->id,
            'unit_name' => $payload['unit'],
            'exit_code' => $payload['exit_code'],
            'exit_status' => $payload['exit_status'],
            'exited_at' => Carbon::parse($payload['at']),
            'recorded_at' => now(),
        ]);

        return response()->json([
            'success' => [
                'data' => ['id' => $event->id],
                'meta' => [
                    'matched' => $resolved !== null,
                ],
            ],
        ], 201);
    }

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Write;
    }

    public function type(): string
    {
        return 'api:POST /events/process';
    }

    public function subject(): ?Model
    {
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function properties(): array
    {
        return [
            'unit' => is_string(request('unit')) ? request('unit') : null,
            'event' => is_string(request('event')) ? request('event') : null,
        ];
    }

    public function description(): ?string
    {
        return null;
    }
}
