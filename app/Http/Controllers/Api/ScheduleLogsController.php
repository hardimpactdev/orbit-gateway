<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Http\Controllers\Api\Concerns\LogsScheduleApiActivity;
use App\Http\Gateway\GatewayApiException;
use App\Models\Node;
use App\Models\Schedule;
use App\Services\Schedules\ScheduleLogsPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class ScheduleLogsController implements Loggable
{
    use LogsScheduleApiActivity;

    public function __construct(
        private ScheduleLogsPayload $payload,
    ) {}

    public function __invoke(Request $request, string $name): JsonResponse
    {
        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return $this->error('authorization_failed', 'Peer identity unknown.', [], 403);
        }

        $run = $this->positiveIntegerQuery($request, 'run');
        $lines = $this->positiveIntegerQuery($request, 'lines') ?? 100;

        if ($run === false) {
            return $this->error('validation_failed', 'The run id must be a positive integer.', ['field' => 'run'], 422);
        }

        if ($lines === false) {
            return $this->error('validation_failed', 'The line limit must be a positive integer.', ['field' => 'lines'], 422);
        }

        try {
            $result = $this->payload->forSchedule(
                name: $name,
                app: $this->stringQuery($request, 'app'),
                node: $this->stringQuery($request, 'node'),
                runId: $run,
                lines: $lines,
                caller: $caller,
            );
            $schedule = Schedule::query()->where('name', $name)->first();

            if ($schedule instanceof Schedule) {
                $this->setScheduleActivitySubject($request, $schedule);
            }
        } catch (GatewayApiException $e) {
            return $this->error($e->errorCode() ?? 'validation_failed', $e->getMessage(), $e->errorMeta(), $this->status($e));
        }

        return response()->json(['success' => $result]);
    }

    private function stringQuery(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function positiveIntegerQuery(Request $request, string $key): int|false|null
    {
        $value = $request->query($key);

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value) || (int) $value < 1) {
            return false;
        }

        return (int) $value;
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

    private function status(GatewayApiException $e): int
    {
        if ($e->errorCode() === 'authorization_failed') {
            return 403;
        }

        if (in_array($e->errorCode(), ['schedule.not_found', 'schedule.run_not_found'], true)) {
            return 404;
        }

        return 422;
    }

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Read;
    }

    public function type(): string
    {
        return 'api:GET /schedules/{name}/logs';
    }
}
