<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Http\Controllers\Api\Concerns\LogsScheduleApiActivity;
use App\Http\Gateway\GatewayApiException;
use App\Models\Node;
use App\Services\Schedules\SchedulePayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class ScheduleListController implements Loggable
{
    use LogsScheduleApiActivity;

    public function __construct(
        private SchedulePayload $payload,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return $this->fail('authorization_failed', 'Peer identity unknown.', [], 403);
        }

        try {
            $data = $this->payload->list(
                app: $this->stringQuery($request, 'app'),
                node: $this->stringQuery($request, 'node'),
                caller: $caller,
            );
        } catch (GatewayApiException $e) {
            return $this->fail(
                code: $e->errorCode() ?? 'validation_failed',
                message: $e->getMessage(),
                meta: $e->errorMeta(),
                status: $this->status($e),
            );
        }

        return response()->json([
            'success' => [
                'data' => ['schedules' => $data['schedules']],
                'meta' => $data['meta'],
            ],
        ]);
    }

    private function stringQuery(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function fail(string $code, string $message, array $meta, int $status): JsonResponse
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
        return $e->errorCode() === 'authorization_failed' ? 403 : 400;
    }

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Read;
    }

    public function type(): string
    {
        return 'api:GET /schedules';
    }
}
