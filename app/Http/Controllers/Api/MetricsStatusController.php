<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Models\Node;
use App\Services\Metrics\MetricsStatusAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MetricsStatusController implements Loggable
{
    private ?Node $activitySubject = null;

    public function __invoke(Request $request, MetricsStatusAction $action): JsonResponse
    {
        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return response()->json([
                'error' => [
                    'code' => 'authorization_failed',
                    'message' => 'Peer identity unknown.',
                    'meta' => (object) [],
                ],
            ], 403);
        }

        $this->activitySubject = $caller;
        $result = $action->read($caller, $this->requestString($request, 'node'));

        if (isset($result['error'])) {
            $error = $result['error'];
            $status = is_int($error['status'] ?? null) ? $error['status'] : 400;
            $meta = is_array($error['meta'] ?? null) ? $error['meta'] : [];

            return response()->json([
                'error' => [
                    'code' => $error['code'],
                    'message' => $error['message'],
                    'meta' => $meta === [] ? (object) [] : $meta,
                ],
            ], $status);
        }

        return response()->json($result);
    }

    private function requestString(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Read;
    }

    public function type(): string
    {
        return 'api:GET /metrics/status';
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
        return [];
    }

    public function description(): ?string
    {
        return null;
    }
}
