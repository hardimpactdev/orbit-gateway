<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Schedules\RemoveSchedule;
use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Http\Controllers\Api\Concerns\LogsScheduleApiActivity;
use App\Http\Gateway\GatewayApiException;
use App\Models\Node;
use App\Services\Schedules\SchedulePayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class ScheduleDestroyController implements Loggable
{
    use LogsScheduleApiActivity;

    public function __construct(
        private SchedulePayload $payload,
    ) {}

    public function __invoke(Request $request, string $name, RemoveSchedule $removeSchedule): JsonResponse
    {
        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return $this->error('authorization_failed', 'Peer identity unknown.', [], 403);
        }

        if ($request->boolean('destructive_consent') !== true) {
            return $this->error('validation_failed', 'Use --force to remove this schedule.', ['field' => 'force'], 422);
        }

        try {
            $schedule = $this->payload->find($name, $this->stringQuery($request, 'app'), $this->stringQuery($request, 'node'), $caller, 'schedule:remove');

            $this->setScheduleActivitySubject($request, $schedule);
            $result = $removeSchedule->handle($schedule);
        } catch (GatewayApiException $e) {
            return $this->error($e->errorCode() ?? 'validation_failed', $e->getMessage(), $e->errorMeta(), $this->status($e), $e->errorData());
        }

        return response()->json(['success' => $result]);
    }

    private function stringQuery(Request $request, string $key): ?string
    {
        $value = $request->query($key);

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
        return $e->errorCode() === 'schedule.not_found' ? 404 : 422;
    }

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Destructive;
    }

    public function type(): string
    {
        return 'api:DELETE /schedules/{name}';
    }
}
