<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Models\Node;
use App\Services\Metrics\MetricsCredentialsAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MetricsCredentialsResetController implements Loggable
{
    private ?Node $activitySubject = null;

    private string $activityNode = '';

    public function __invoke(Request $request, MetricsCredentialsAction $action): JsonResponse
    {
        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return $this->authorizationFailed();
        }

        $result = $action->reset($caller, $this->requestString($request, 'node'));

        if (isset($result['error'])) {
            return $this->errorResponse($result['error']);
        }

        $this->activitySubject = $caller;
        $credentials = $result['success']['data']['credentials'] ?? [];
        $this->activityNode = is_array($credentials) && is_string($credentials['node'] ?? null)
            ? $credentials['node']
            : '';

        return response()->json($result);
    }

    /**
     * @param  array<string, mixed>  $error
     */
    private function errorResponse(array $error): JsonResponse
    {
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

    private function authorizationFailed(): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'authorization_failed',
                'message' => 'Peer identity unknown.',
                'meta' => (object) [],
            ],
        ], 403);
    }

    private function requestString(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Write;
    }

    public function type(): string
    {
        return 'api:POST /metrics/credentials/reset';
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
            'node' => $this->activityNode,
        ];
    }

    public function description(): ?string
    {
        return null;
    }
}
