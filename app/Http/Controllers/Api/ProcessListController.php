<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Http\Gateway\GatewayApiException;
use App\Models\Node;
use App\Services\Processes\ProcessListPayload;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class ProcessListController implements Loggable
{
    public function __construct(
        private ProcessListPayload $payload,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return $this->fail('authorization_failed', 'Peer identity unknown.', [], 403);
        }

        try {
            $data = $this->payload->forContext(
                nodeName: $this->stringQuery($request, 'node'),
                appName: $this->stringQuery($request, 'app'),
                workspaceName: $this->stringQuery($request, 'workspace'),
                caller: $caller,
            );
        } catch (GatewayApiException $e) {
            return $this->fail(
                code: $e->errorCode() ?? 'validation_failed',
                message: $e->getMessage(),
                meta: $e->errorMeta(),
                status: $e->errorCode() === 'authorization_failed' ? 403 : 400,
            );
        }

        return response()->json([
            'success' => [
                'data' => $data,
                'meta' => (object) [],
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

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Read;
    }

    public function activityLogType(): ActivityLogType
    {
        return $this->effect();
    }

    public function type(): string
    {
        return 'api:GET /processes';
    }

    public function activityLogAction(): string
    {
        return $this->type();
    }

    public function subject(): ?Model
    {
        return null;
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
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public function activityLogProperties(): array
    {
        return $this->properties();
    }

    public function description(): ?string
    {
        return null;
    }

    public function activityLogDescription(): ?string
    {
        return $this->description();
    }
}
